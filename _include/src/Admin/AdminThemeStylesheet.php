<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin;

use Register\Core\Config\DynamicConfigProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminThemeStylesheet
{
    public const string ACTION = 'theme-stylesheet';

    public const string COLOR_PATTERN = '~\A#[0-9a-f]{6}\z~Di';

    private const string DEFAULT_COLOR = '#eeeeee';

    public function __construct(private DynamicConfigProvider $dynamicConfigProvider)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->query->getString('action') === self::ACTION;
    }

    public function handle(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_GET) && !$request->isMethod(Request::METHOD_HEAD)) {
            return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, [
                'Allow' => Request::METHOD_GET . ', ' . Request::METHOD_HEAD,
            ]);
        }

        $color = $this->storedColor();
        if ($request->query->has('color')) {
            $color = $request->query->getString('color');
            if (!self::isValidColor($color)) {
                return new Response('', Response::HTTP_BAD_REQUEST, $this->headers());
            }
        }

        $content = $request->isMethod(Request::METHOD_HEAD)
            ? ''
            : ":root {\n    --page-secondary-background: " . strtolower($color) . ";\n}\n";

        return new Response($content, Response::HTTP_OK, $this->headers());
    }

    public static function isValidColor(string $color): bool
    {
        return preg_match(self::COLOR_PATTERN, $color) === 1;
    }

    private function storedColor(): string
    {
        try {
            $color = $this->dynamicConfigProvider->get('REGISTER_ADMIN_COLOR');
        } catch (\LogicException) {
            return self::DEFAULT_COLOR;
        }

        return \is_string($color) && self::isValidColor($color) ? $color : self::DEFAULT_COLOR;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type'                 => 'text/css; charset=UTF-8',
            'Cache-Control'                => 'no-store, private',
            'X-Content-Type-Options'       => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
