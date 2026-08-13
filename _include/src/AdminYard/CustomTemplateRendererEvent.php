<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard;

class CustomTemplateRendererEvent
{
    /**
     * @var array<mixed>
     */
    public array $extraStyles = [];

    /**
     * @var array<mixed>
     */
    public array $extraScripts = [];

    public function __construct(public readonly string $basePath)
    {
    }
}
