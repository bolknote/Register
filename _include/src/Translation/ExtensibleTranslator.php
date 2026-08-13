<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Translation;

use S2\Cms\Config\StringProxy;
use S2\Cms\Framework\StatefulServiceInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

class ExtensibleTranslator implements TranslatorInterface, StatefulServiceInterface
{
    use TranslatorTrait {
        TranslatorTrait::trans as protected parentTrans;
        TranslatorTrait::getLocale as protected parentGetLocale;
    }

    /**
     * @var array<string, \Closure>
     */
    private array $loaders = [];

    /**
     * @var array<mixed>
     */
    private array $translations = [];

    /**
     * @var array<mixed>|null
     */
    private ?array $loadingQueue = null;

    public function __construct(private readonly StringProxy $language)
    {
    }

    /**
     * @param array<mixed> $parameters
     */
    #[\Override]
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->processQueueIfRequired();

        $id = $id !== null && isset($this->translations[$id]) ? (string)$this->translations[$id] : $id;

        return $this->parentTrans($id, $parameters, $domain, $locale);
    }

    #[\Override]
    public function getLocale(): string
    {
        $this->processQueueIfRequired();

        return $this->parentGetLocale();
    }

    public function attachLoader(string $namespace, \Closure $closure): void
    {
        if (isset($this->loaders[$namespace])) {
            return;
        }

        $this->loaders[$namespace] = $closure;
        $this->markAsRequired($namespace);
    }

    private function markAsRequired(string $namespace): void
    {
        if ($this->loadingQueue === null) {
            $this->loadingQueue = [$namespace => true];
        } else {
            $this->loadingQueue[$namespace] = true;
        }
    }

    #[\Override]
    public function clearState(): void
    {
        $this->translations = [];
        foreach (array_keys($this->loaders) as $namespace) {
            $this->markAsRequired($namespace);
        }
    }

    private function processQueueIfRequired(): void
    {
        if ($this->loadingQueue !== null) {
            $language = $this->language->get();
            foreach (array_keys($this->loadingQueue) as $namespace) {
                if (isset($this->loaders[$namespace])) {
                    /** @noinspection SlowArrayOperationsInLoopInspection */
                    $this->translations = array_merge($this->loaders[$namespace]($language, $this), $this->translations);
                }
            }

            $this->loadingQueue = null;
        }
    }
}
