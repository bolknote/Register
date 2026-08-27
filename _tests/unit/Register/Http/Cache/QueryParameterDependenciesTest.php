<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\QueryParameterDependencies;
use Symfony\Component\HttpFoundation\Request;

final class QueryParameterDependenciesTest extends TestCase
{
    public function testAllParametersAffectTheResponse(): void
    {
        $dependencies = QueryParameterDependencies::all();

        self::assertFalse($dependencies->affectResponse(Request::create('/article')));
        self::assertTrue($dependencies->affectResponse(Request::create('/article?anything=1')));
    }

    public function testNoParametersAffectTheResponse(): void
    {
        $dependencies = QueryParameterDependencies::none();

        self::assertFalse($dependencies->affectResponse(Request::create('/article?anything=1')));
    }

    public function testOnlyDeclaredParametersAffectTheResponse(): void
    {
        $dependencies = QueryParameterDependencies::only('p', 'format');

        self::assertFalse($dependencies->affectResponse(Request::create('/article?utm_source=test')));
        self::assertTrue($dependencies->affectResponse(Request::create('/article?p=2')));
        self::assertTrue($dependencies->affectResponse(Request::create('/article?format=compact&utm_source=test')));
    }

    public function testEmptyDependencyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QueryParameterDependencies::only('');
    }
}
