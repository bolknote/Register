<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Symfony\Component\HttpFoundation\Request;

/** Declares which query parameters can change a cached response representation. */
final readonly class QueryParameterDependencies
{
    /** @param array<string, true> $parameterNames */
    private function __construct(
        private bool  $allParameters,
        private array $parameterNames,
    ) {
    }

    /** Every query parameter can affect the response. */
    public static function all(): self
    {
        return new self(true, []);
    }

    /** No query parameter affects the response. */
    public static function none(): self
    {
        return new self(false, []);
    }

    /** Only the named query parameters can affect the response. */
    public static function only(string ...$parameterNames): self
    {
        $dependencies = [];
        foreach ($parameterNames as $parameterName) {
            if ($parameterName === '') {
                throw new \InvalidArgumentException('A query parameter dependency cannot be empty.');
            }

            $dependencies[$parameterName] = true;
        }

        return new self(false, $dependencies);
    }

    public function affectResponse(Request $request): bool
    {
        if ($request->query->count() === 0) {
            return false;
        }

        if ($this->allParameters) {
            return true;
        }

        foreach (array_keys($this->parameterNames) as $parameterName) {
            if ($request->query->has($parameterName)) {
                return true;
            }
        }

        return false;
    }
}
