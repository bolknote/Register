<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin;

readonly class ResourceProvider
{
    public function __construct(private string $rootDir)
    {
    }

    /**
     * Languages available in current S2 installation
     * @return list<string>
     */
    public function readLanguages(): array
    {
        $result = [];

        $directory = dir($this->rootDir . '_lang');
        if ($directory === false) {
            throw new \RuntimeException('Unable to open the language directory.');
        }

        while (($entry = $directory->read()) !== false) {
            if ($entry !== '.' && $entry !== '..' && is_dir($this->rootDir . '_lang/' . $entry) && file_exists($this->rootDir . '_lang/' . $entry . '/common.php')) {
                $result[] = $entry;
            }
        }

        $directory->close();

        return $result;
    }

    /**
     * Languages available in current S2 installation, indexed by their stable identifiers.
     *
     * @return array<string, string>
     */
    public function readLanguageOptions(string $locale): array
    {
        $result = [];

        foreach ($this->readLanguages() as $language) {
            $result[$language] = $this->readLocalizedName(
                $this->rootDir . '_lang/' . $language . '/language.json',
                $language,
                $locale,
            );
        }

        return $result;
    }

    /**
     * Styles available in current S2 installation
     * @return list<string>
     */
    public function readStyles(): array
    {
        $result = [];

        $directory = dir($this->rootDir . '_styles');
        if ($directory === false) {
            throw new \RuntimeException('Unable to open the styles directory.');
        }

        while (($entry = $directory->read()) !== false) {
            if ($entry !== '.' && $entry !== '..' && is_dir($this->rootDir . '_styles/' . $entry) && file_exists($this->rootDir . '_styles/' . $entry . '/' . $entry . '.php')) {
                $result[] = $entry;
            }
        }

        $directory->close();

        return $result;
    }

    /**
     * Styles available in current S2 installation, indexed by their stable identifiers.
     *
     * @return array<string, string>
     */
    public function readStyleOptions(string $locale): array
    {
        $result = [];

        foreach ($this->readStyles() as $style) {
            $result[$style] = $this->readStyleName($style, $locale);
        }

        return $result;
    }

    private function readStyleName(string $style, string $locale): string
    {
        $fallbackName = ucfirst(str_replace(['-', '_'], ' ', $style));
        $metadataFile = $this->rootDir . '_styles/' . $style . '/style.json';

        return $this->readLocalizedName($metadataFile, $fallbackName, $locale);
    }

    private function readLocalizedName(string $metadataFile, string $fallbackName, string $locale): string
    {
        $contents = s2_call_without_warnings(
            static fn(): string|false => file_get_contents($metadataFile)
        );

        if ($contents === false) {
            return $fallbackName;
        }

        try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fallbackName;
        }

        if (!\is_array($metadata) || !isset($metadata['name']) || !\is_array($metadata['name'])) {
            return $fallbackName;
        }

        $normalizedLocale = strtolower(str_replace('_', '-', trim($locale)));
        $language         = explode('-', $normalizedLocale, 2)[0];

        foreach (array_unique([$normalizedLocale, $language, 'en']) as $candidate) {
            $name = $metadata['name'][$candidate] ?? null;
            if (\is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return $fallbackName;
    }
}
