<?php /** @noinspection PhpComposerExtensionStubsInspection */
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\HttpClient;

/**
 * @phpstan-type RequestOptions array{connect_timeout?: positive-int, read_timeout?: positive-int, follow_redirects?: bool}
 * @psalm-type RequestOptions = array{connect_timeout?: positive-int, read_timeout?: positive-int, follow_redirects?: bool}
 * @phpstan-type ParsedUrl array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}
 * @psalm-type ParsedUrl = array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}
 */
readonly class HttpClient
{
    public const string TRANSPORT_CURL = 'curl';

    public const string TRANSPORT_FSOCKOPEN = 'fsockopen';

    public const string TRANSPORT_FILE_GET_CONTENTS = 'file_get_contents';

    public const string CONNECT_TIMEOUT = 'connect_timeout';

    public const string READ_TIMEOUT = 'read_timeout';

    public const string FOLLOW_REDIRECTS = 'follow_redirects';

    private const array ALLOWED_METHODS             = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

    private const array MANAGED_REQUEST_HEADERS = [
        'connection',
        'content-length',
        'host',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    private const array SENSITIVE_REDIRECT_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    /** @var non-empty-string */
    private string $userAgent;

    public function __construct(
        private int     $timeout = 10,
        private int     $maxRedirects = 10,
        string          $userAgent = 'Register',
        private bool    $verifySsl = true,
        private ?string $preferredTransport = null,
    ) {
        if ($userAgent === '' || preg_match('/[\x00-\x1f\x7f]/', $userAgent) === 1) {
            throw new \InvalidArgumentException('User-Agent cannot be empty or contain control characters.');
        }

        $this->userAgent = $userAgent;

        if ($preferredTransport !== null && !\in_array($this->preferredTransport, [
                self::TRANSPORT_CURL,
                self::TRANSPORT_FSOCKOPEN,
                self::TRANSPORT_FILE_GET_CONTENTS
            ], true)) {
            throw new \InvalidArgumentException(\sprintf('Transport "%s" is not supported', $preferredTransport));
        }
    }

    /**
     * @throws HttpClientException
     * @param array<string, string> $headers
     * @param RequestOptions $options
     */
    public function request(
        string  $method,
        string  $url,
        array   $headers = [],
        ?string $body = null,
        array   $options = [],
    ): HttpResponse {
        $method = $this->normalizeMethod($method);
        $url = $this->normalizeUrl($url);
        $this->validateHeaders($headers);

        return match ($this->getPreferredTransport()) {
            self::TRANSPORT_CURL => $this->requestWithCurl($method, $url, $headers, $body, $options),
            self::TRANSPORT_FSOCKOPEN => $this->requestWithFsockopen($method, $url, $headers, $body, $options),
            self::TRANSPORT_FILE_GET_CONTENTS => $this->requestWithFileGetContents($method, $url, $headers, $body, $options),
            default => throw new HttpClientException('No available method to fetch the URL'),
        };
    }

    /**
     * @throws HttpClientException
     */
    public function fetch(string $url): HttpResponse
    {
        return $this->request('GET', $url);
    }

    /**
     * @throws HttpClientException
     * @throws \JsonException
     * @param array<string, mixed> $body
     * @param RequestOptions $options
     */
    public function postJson(string $url, array $body, array $options = []): HttpResponse
    {
        return $this->request(
            'POST',
            $url,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
            $options
        );
    }

    /**
     * @throws HttpClientException
     * @param array<string, mixed> $body
     * @param RequestOptions $options
     */
    public function post(string $url, array $body, array $options = []): HttpResponse
    {
        return $this->request(
            'POST',
            $url,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($body),
            $options
        );
    }

    /**
     * @throws HttpClientException
     */
    /** @return non-empty-string */
    private function normalizeMethod(string $method): string
    {
        $method = strtoupper($method);
        if ($method === '' || !\in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported HTTP method: %s', $method));
        }

        return $method;
    }

    /** @return non-empty-string */
    private function normalizeUrl(string $url): string
    {
        if ($url === '') {
            throw new HttpClientException('URL cannot be empty');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '\\')) {
            throw new HttpClientException('Invalid URL: ' . $url);
        }

        $parsedUrl = parse_url($url);
        if (!\is_array($parsedUrl) || !isset($parsedUrl['host']) || $parsedUrl['host'] === '') {
            throw new HttpClientException('Invalid URL: ' . $url);
        }

        $scheme = strtolower($parsedUrl['scheme'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new HttpClientException('Unsupported URL scheme: ' . ($scheme === '' ? '(missing)' : $scheme));
        }

        if (isset($parsedUrl['user']) || isset($parsedUrl['pass'])) {
            throw new HttpClientException('URL credentials are not allowed');
        }

        if (isset($parsedUrl['fragment'])) {
            $fragmentPosition = strpos($url, '#');
            if ($fragmentPosition !== false) {
                $url = substr($url, 0, $fragmentPosition);
            }
        }

        return $scheme . substr($url, \strlen($scheme));
    }

    /** @return non-empty-string */
    private function newUrlFromLocation(string $location, string $currentUrl): string
    {
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1 || str_contains($location, '\\')) {
            throw new HttpClientException('Invalid redirect URL: ' . $location);
        }

        $parsedCurrentUrl = parse_url($currentUrl);
        $parsedLocation   = parse_url($location);
        if (!\is_array($parsedCurrentUrl) || !\is_array($parsedLocation)) {
            throw new HttpClientException('Invalid redirect URL: ' . $location);
        }

        if (isset($parsedLocation['scheme'])) {
            return $this->normalizeRedirectTarget($location, $currentUrl);
        }

        if (str_starts_with($location, '//')) {
            return $this->normalizeRedirectTarget(($parsedCurrentUrl['scheme'] ?? 'https') . ':' . $location, $currentUrl);
        }

        $target = [
            'scheme' => $parsedCurrentUrl['scheme'] ?? '',
            'host'   => $parsedCurrentUrl['host'] ?? '',
        ];
        if (isset($parsedCurrentUrl['port'])) {
            $target['port'] = $parsedCurrentUrl['port'];
        }

        $currentPath = $parsedCurrentUrl['path'] ?? '/';
        $locationPath = $parsedLocation['path'] ?? '';
        if ($locationPath === '') {
            $target['path'] = $currentPath;
            if (isset($parsedLocation['query'])) {
                $target['query'] = $parsedLocation['query'];
            } elseif (isset($parsedCurrentUrl['query'])) {
                $target['query'] = $parsedCurrentUrl['query'];
            }
        } else {
            $target['path'] = str_starts_with($locationPath, '/')
                ? $this->normalizePath($locationPath)
                : $this->normalizePath(substr($currentPath, 0, (int)strrpos($currentPath, '/') + 1) . $locationPath);
            if (isset($parsedLocation['query'])) {
                $target['query'] = $parsedLocation['query'];
            }
        }

        return $this->normalizeRedirectTarget($this->unparseUrl($target), $currentUrl);
    }

    /** @return non-empty-string */
    private function normalizeRedirectTarget(string $targetUrl, string $currentUrl): string
    {
        $targetUrl = $this->normalizeUrl($targetUrl);
        if (parse_url($currentUrl, PHP_URL_SCHEME) === 'https' && parse_url($targetUrl, PHP_URL_SCHEME) === 'http') {
            throw new HttpClientException('HTTPS redirects must not downgrade to HTTP');
        }

        return $targetUrl;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if ($normalized !== '/' && (str_ends_with($path, '/') || str_ends_with($path, '/.') || str_ends_with($path, '/..'))) {
            $normalized .= '/';
        }

        return $normalized;
    }

    /**
     * @param ParsedUrl $parsed
     */
    private function unparseUrl(array $parsed): string
    {
        $pass      = $parsed['pass'] ?? null;
        $user      = $parsed['user'] ?? null;
        $userinfo  = $pass !== null ? "$user:$pass" : $user;
        $port      = $parsed['port'] ?? 0;
        $scheme    = $parsed['scheme'] ?? "";
        $query     = $parsed['query'] ?? "";
        $fragment  = $parsed['fragment'] ?? "";
        $authority = ($userinfo !== null ? "$userinfo@" : "") .
            ($parsed['host'] ?? "") .
            ($port !== 0 ? ":$port" : "");

        return (
            ($scheme !== '' ? "$scheme:" : "") .
            ($authority !== '' ? "//$authority" : "") .
            ($parsed['path'] ?? "") .
            ($query !== '' ? "?$query" : "") .
            ($fragment !== '' ? "#$fragment" : "")
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function validateHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid HTTP header name: ' . $name);
            }

            if (\in_array(strtolower($name), self::MANAGED_REQUEST_HEADERS, true)) {
                throw new \InvalidArgumentException('HTTP header is managed by the client: ' . $name);
            }

            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('HTTP header values cannot contain unsafe control characters.');
            }
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function headersForRedirect(array $headers, string $currentUrl, string $targetUrl): array
    {
        if ($this->origin($currentUrl) === $this->origin($targetUrl)) {
            return $headers;
        }

        foreach (array_keys($headers) as $name) {
            if (\in_array(strtolower($name), self::SENSITIVE_REDIRECT_HEADERS, true)) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    private function origin(string $url): string
    {
        $scheme = (string)parse_url($url, PHP_URL_SCHEME);
        $host   = strtolower((string)parse_url($url, PHP_URL_HOST));
        $port   = parse_url($url, PHP_URL_PORT);
        $port   = \is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . $host . ':' . $port;
    }

    /** @return array<string, bool|string> */
    private function sslContextOptions(string $host): array
    {
        return [
            'verify_peer'       => $this->verifySsl,
            'verify_peer_name'  => $this->verifySsl,
            'allow_self_signed' => !$this->verifySsl,
            'peer_name'         => trim($host, '[]'),
            'SNI_enabled'       => true,
        ];
    }

    /**
     * @throws HttpClientException
     * @param non-empty-string $method
     * @param non-empty-string $url
     * @param string[] $requestHeaders
     * @param RequestOptions $options
     */
    private function requestWithCurl(
        string  $method,
        string  $url,
        array   $requestHeaders,
        ?string $requestBody,
        array   $options = [],
        int     $redirects = 0
    ): HttpResponse {
        if ($redirects > $this->maxRedirects) {
            throw new HttpClientException('Too many redirects');
        }

        $ch = curl_init();
        if (!$ch instanceof \CurlHandle) {
            throw new HttpClientException('Unable to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options[self::CONNECT_TIMEOUT] ?? $this->timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, isset($options[self::CONNECT_TIMEOUT], $options[self::READ_TIMEOUT]) ? $options[self::CONNECT_TIMEOUT] + $options[self::READ_TIMEOUT] : $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        if ($requestBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        }

        if ($requestHeaders !== []) {
            $formattedHeaders = array_map(static fn($k, $v): string => "$k: $v", array_keys($requestHeaders), $requestHeaders);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        if (parse_url($url, PHP_URL_SCHEME) === 'https') {
            /** @noinspection CurlSslServerSpoofingInspection */
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);
        }

        $content      = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorNum     = curl_errno($ch);
        $error        = $errorNum !== 0 ? curl_error($ch) : null;

        if (!\is_string($content) || $error !== null) {
            throw new HttpClientException($error ?? 'Unknown error', match ($errorNum) {
                CURLE_OPERATION_TIMEOUTED => HttpClientException::REASON_TIMEOUT,
                CURLE_COULDNT_RESOLVE_HOST => HttpClientException::REASON_HOST_RESOLVE_FAILURE,
                default => null
            });
        }

        $contentStart = strpos($content, "\r\n\r\n");
        if ($contentStart !== false) {
            [$rawHeaders, $content] = explode("\r\n\r\n", $content, 2);
        } else {
            $rawHeaders = '';
        }

        $responseHeaders = explode("\r\n", $rawHeaders);

        if (($options[self::FOLLOW_REDIRECTS] ?? true)
            && $responseCode >= 300
            && $responseCode < 400
            && preg_match('/^Location:\s*(.*)/mi', $rawHeaders, $matches) === 1
        ) {
            $targetUrl = $this->newUrlFromLocation(trim($matches[1]), $url);

            return $this->requestWithCurl(
                $method,
                $targetUrl,
                $this->headersForRedirect($requestHeaders, $url, $targetUrl),
                $requestBody,
                $options,
                $redirects + 1,
            );
        }

        return $this->createResponse($responseCode, $responseHeaders, $content);
    }

    /**
     * @throws HttpClientException
     * @param string[] $requestHeaders
     * @param RequestOptions $options
     */
    private function requestWithFsockopen(
        string  $method,
        string  $url,
        array   $requestHeaders = [],
        ?string $requestBody = null,
        array   $options = [],
        int     $redirects = 0
    ): HttpResponse {
        if ($redirects > $this->maxRedirects) {
            throw new HttpClientException('Too many redirects');
        }

        $connectTimeout = $options[self::CONNECT_TIMEOUT] ?? $this->timeout;
        $readTimeout    = $options[self::READ_TIMEOUT] ?? $this->timeout;

        $parsedUrl     = parse_url($url);
        $scheme        = $parsedUrl['scheme'] ?? 'http';
        $host          = $parsedUrl['host'] ?? '';
        $port          = $parsedUrl['port'] ?? ($scheme === 'https' ? 443 : 80);
        $socketHost    = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        $socketContext = stream_context_create($scheme === 'https' ? ['ssl' => $this->sslContextOptions($host)] : []);
        $errno         = 0;
        $errstr        = '';
        $socketWarning = null;
        $remote        = s2_call_without_warnings(
            static function () use ($scheme, $socketHost, $port, $connectTimeout, $socketContext, &$errno, &$errstr) {
                return stream_socket_client(
                    ($scheme === 'https' ? 'ssl://' : 'tcp://') . $socketHost . ':' . $port,
                    $errno,
                    $errstr,
                    $connectTimeout,
                    STREAM_CLIENT_CONNECT,
                    $socketContext,
                );
            },
            $socketWarning
        );

        if ($remote === false) {
            $errorMessage = $this->connectionErrorMessage($errstr, $socketWarning);
            throw new HttpClientException($errorMessage, match (true) {
                $errno === 110 || preg_match('/timed?[\s_-]?out/i', $errorMessage) === 1 => HttpClientException::REASON_TIMEOUT,
                str_contains($errorMessage, 'getaddrinfo') => HttpClientException::REASON_HOST_RESOLVE_FAILURE,
                default => null
            });
        }

        stream_set_timeout($remote, $readTimeout);

        $path = ($parsedUrl['path'] ?? '/')
            . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $hostHeader  = $host . ($port !== $defaultPort ? ':' . $port : '');

        $request = strtoupper($method) . ' ' . $path . " HTTP/1.0\r\n";
        $request .= "Host: $hostHeader\r\n";
        $request .= "User-Agent: {$this->userAgent}\r\n";
        $request .= "Connection: Close\r\n";

        foreach ($requestHeaders as $name => $value) {
            $request .= "$name: $value\r\n";
        }

        if ($requestBody !== null) {
            $request .= "Content-Length: " . \strlen($requestBody) . "\r\n";
            $request .= "\r\n" . $requestBody;
        } else {
            $request .= "\r\n";
        }

        fwrite($remote, $request);

        $content = stream_get_contents($remote);
        $meta    = stream_get_meta_data($remote);
        fclose($remote);
        if ($content === false) {
            throw new HttpClientException('Unable to read the response body');
        }

        if ($meta['timed_out']) {
            throw new HttpClientException('Read timed out', HttpClientException::REASON_TIMEOUT);
        }

        $contentStart = strpos($content, "\r\n\r\n");
        if ($contentStart !== false) {
            [$rawHeaders, $content] = explode("\r\n\r\n", $content, 2);
        } else {
            $rawHeaders = '';
        }

        $responseHeaders = explode("\r\n", $rawHeaders);

        $responseCode = preg_match('/\d{3}/', $responseHeaders[0], $matches) === 1 ? (int)$matches[0] : 0;

        if (($options[self::FOLLOW_REDIRECTS] ?? true)
            && $responseCode >= 300
            && $responseCode < 400
            && preg_match('/^Location:\s*(.*)/mi', $rawHeaders, $matches) === 1
        ) {
            $targetUrl = $this->newUrlFromLocation(trim($matches[1]), $url);

            return $this->requestWithFsockopen(
                $method,
                $targetUrl,
                $this->headersForRedirect($requestHeaders, $url, $targetUrl),
                $requestBody,
                $options,
                $redirects + 1,
            );
        }

        return $this->createResponse($responseCode, $responseHeaders, $content);
    }

    private function connectionErrorMessage(mixed $socketError, ?string $socketWarning): string
    {
        if (\is_string($socketError) && $socketError !== '') {
            return $socketError;
        }

        return $socketWarning ?? 'Connection failed';
    }

    /**
     * @throws HttpClientException
     * @param string[] $requestHeaders
     * @param RequestOptions $options
     */
    private function requestWithFileGetContents(
        string  $method,
        string  $url,
        array   $requestHeaders,
        ?string $requestBody,
        array   $options = [],
        int     $redirects = 0,
    ): HttpResponse {
        if ($redirects > $this->maxRedirects) {
            throw new HttpClientException('Too many redirects');
        }

        // NOTE: it seems like the PHP HTTP stream wrapper does not support connection timeout
        $readTimeout = $options[self::READ_TIMEOUT] ?? $this->timeout;
        $host        = (string)parse_url($url, PHP_URL_HOST);

        $headerLines = array_map(static fn($k, $v): string => "$k: $v", array_keys($requestHeaders), $requestHeaders);
        $context     = stream_context_create([
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headerLines),
                'content'       => $requestBody ?? '',
                'user_agent'    => $this->userAgent,
                'follow_location' => 0,
                'max_redirects' => 1,
                'timeout'       => $readTimeout,
                'ignore_errors' => true,
            ],
            'ssl' => $this->sslContextOptions($host),
        ]);

        $fetch = static function () use ($url, $context): array {
            $stream = fopen($url, 'rb', false, $context);
            if ($stream === false) {
                return [false, []];
            }

            try {
                $content  = stream_get_contents($stream);
                $metadata = stream_get_meta_data($stream);
            } finally {
                fclose($stream);
            }

            if ($content === false) {
                return [false, []];
            }

            $wrapperData = $metadata['wrapper_data'] ?? [];
            if (\is_string($wrapperData)) {
                return [$content, [$wrapperData]];
            }

            if (!\is_array($wrapperData)) {
                return [$content, []];
            }

            $responseHeaders = [];
            foreach ($wrapperData as $header) {
                if (\is_string($header)) {
                    $responseHeaders[] = $header;
                }
            }

            return [$content, $responseHeaders];
        };

        $start          = microtime(true);
        $warningMessage = null;
        [$content, $rawResponseHeaders] = s2_call_without_warnings($fetch, $warningMessage);
        if ($content === false) {
            $errorMessage = $warningMessage ?? 'Unable to fetch the URL';
            throw new HttpClientException($errorMessage, match (true) {
                preg_match('/timed?[\s_-]?out/i', $errorMessage) === 1 => HttpClientException::REASON_TIMEOUT,
                str_contains($errorMessage, 'HTTP request failed') && (microtime(true) - $start >= $readTimeout) => HttpClientException::REASON_TIMEOUT,
                str_contains($errorMessage, 'getaddrinfo') => HttpClientException::REASON_HOST_RESOLVE_FAILURE,
                default => null
            });
        }

        $responseCode       = 0;
        $responseHeaders    = [];
        foreach ($rawResponseHeaders as $value) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $value, $matches) === 1) {
                $responseCode    = (int)$matches[1];
                $responseHeaders = []; // Reset old headers from previous request
            }

            $responseHeaders[] = $value;
        }

        if (($options[self::FOLLOW_REDIRECTS] ?? true) && $responseCode >= 300 && $responseCode < 400) {
            foreach ($responseHeaders as $header) {
                if (preg_match('/^Location:\s*(.*)/i', $header, $matches) === 1) {
                    $targetUrl = $this->newUrlFromLocation(trim($matches[1]), $url);

                    return $this->requestWithFileGetContents(
                        $method,
                        $targetUrl,
                        $this->headersForRedirect($requestHeaders, $url, $targetUrl),
                        $requestBody,
                        $options,
                        $redirects + 1,
                    );
                }
            }
        }

        return $this->createResponse($responseCode, $responseHeaders, $content);
    }

    /**
     * @param string[] $headers
     */
    private function createResponse(int $responseCode, array $headers, string $content): HttpResponse
    {
        return new HttpResponse(
            headers: $headers,
            statusCode: $responseCode,
            content: $content,
            error: $responseCode >= 400 ? "HTTP Error $responseCode" : null
        );
    }

    private function getPreferredTransport(): ?string
    {
        if ($this->preferredTransport !== null) {
            return $this->preferredTransport;
        }

        if (\function_exists('curl_init') && \function_exists('curl_exec')) {
            return self::TRANSPORT_CURL;
        }

        if (\function_exists('fsockopen')) {
            return self::TRANSPORT_FSOCKOPEN;
        }

        $allowUrlFopen = \ini_get('allow_url_fopen');
        if (\is_string($allowUrlFopen) && \in_array(strtolower($allowUrlFopen), ['on', 'true', '1'], true)) {
            return self::TRANSPORT_FILE_GET_CONTENTS;
        }

        return null;
    }
}
