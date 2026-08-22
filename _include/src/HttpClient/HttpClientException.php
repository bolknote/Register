<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\HttpClient;

class HttpClientException extends \RuntimeException
{
    public const string REASON_TIMEOUT = 'timeout';

    public const string REASON_HOST_RESOLVE_FAILURE = 'host_resolve_failure';

    public function __construct(string $message = '', public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
