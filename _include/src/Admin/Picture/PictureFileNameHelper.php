<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Picture;

use S2\AdminYard\Translator;
use Symfony\Component\HttpFoundation\Response;

readonly class PictureFileNameHelper
{
    public function __construct(
        private Translator $translator,
        private string     $allowedExtensions,
    ) {
    }

    public function normalizeFileName(string $filename): string
    {
        $filename = mb_strtolower($this->baseName($filename));
        $filename = str_replace("\0", '', $filename);
        while (str_contains($filename, '..')) {
            $filename = str_replace('..', '', $filename);
        }

        return $filename;
    }

    public function assertAllowedExtension(string $filename): void
    {
        $extension = '';
        $dotPos = strrpos($filename, '.');
        if ($dotPos !== false) {
            $extension = mb_strtolower(substr($filename, $dotPos + 1));
        }

        if (
            $this->allowedExtensions !== ''
            && $extension !== ''
            && !str_contains(' ' . $this->allowedExtensions . ' ', ' ' . $extension . ' ')
        ) {
            $errorMessage = $this->translator->trans('Forbidden extension', ['{{ ext }}' => $extension]);
            $error        = $filename !== '' ? \sprintf($this->translator->trans('Upload file error'), $filename, $errorMessage) : $errorMessage;
            throw new \RuntimeException($error, Response::HTTP_FORBIDDEN);
        }
    }

    public function incrementCopySuffix(string $filename): string
    {
        return preg_replace_callback('#(?:|_copy|_copy\((\d+)\))(?=(?:\.[^\.]*)?$)#', static function (array $match): string {
            if ($match[0] === '') {
                return '_copy';
            }

            if ($match[0] === '_copy') {
                return '_copy(2)';
            }

            return '_copy(' . ((int)($match[1] ?? 1) + 1) . ')';
        }, $filename, 1) ?? throw new \RuntimeException('Unable to increment the file copy suffix.');
    }

    private function baseName(string $dir): string
    {
        return false !== ($pos = strrpos($dir, '/')) ? substr($dir, $pos + 1) : $dir;
    }
}
