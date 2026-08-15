<?php /** @noinspection HtmlUnknownTarget */
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license   https://opensource.org/license/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Image;

use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\QueueExecutionBudget;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ThumbnailGenerator implements QueueHandlerInterface
{
    private const string QUEUE_CODE = 'thumbnail';

    private const string CACHE_SUBDIRECTORY = '/cache/';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly QueuePublisher           $publisher,
        private readonly string                   $cacheUrlPrefix,
        private string                            $cacheFilesystemPrefix
    ) {
        $this->cacheFilesystemPrefix = rtrim($cacheFilesystemPrefix, '/');
    }

    /**
     * @param string $src            URL of the image
     * @param string $originalWidth  Attr content (may not be valid)
     * @param string $originalHeight Attr content (may not be valid)
     * @param int    $maxWidth       Limit for the thumbnail
     * @param int    $maxHeight      Limit for the thumbnail
     *
     * @return string HTML Markup
     */
    public function getThumbnailHtml(string $src, string $originalWidth, string $originalHeight, int $maxWidth, int $maxHeight): string
    {
        $event = new ThumbnailGenerateEvent($src, $originalWidth, $originalHeight, $maxWidth, $maxHeight);
        $this->eventDispatcher->dispatch($event);
        $result = $event->getResult();
        if ($result !== null) {
            return $result;
        }

        try {
            [$newWidth, $newHeight] = self::reduceSize($originalWidth, $originalHeight, $maxWidth, $maxHeight);
            $src = $this->getThumbnailSrc($src, 2 * $newWidth, 2 * $newHeight); // 2 for retina

            return sprintf('<img src="%s" width="%s" height="%s" alt="">', $src, $newWidth, $newHeight);
        } catch (\InvalidArgumentException) {
            return sprintf('<img src="%s" alt="">', $src);
        }
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::QUEUE_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.5;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        if ($code !== self::QUEUE_CODE) {
            throw new \LogicException(\sprintf('Unsupported thumbnail queue code "%s".', $code));
        }

        if (
            \count($payload) !== 3
            || !\is_string($payload[0] ?? null)
            || !\is_int($payload[1] ?? null)
            || !\is_int($payload[2] ?? null)
            || $payload[1] < 1
            || $payload[2] < 1
        ) {
            throw new \InvalidArgumentException('Invalid thumbnail queue payload.');
        }

        [$src, $width, $height] = $payload;

        $budget->checkpoint($this->minimumExecutionTime());

        // Check if $src file is in the pictures dir
        if (!str_starts_with($src, $this->cacheUrlPrefix . '/')) {
            throw new \InvalidArgumentException('Thumbnail source is outside of the configured picture directory.');
        }

        $filename = $this->cacheFilesystemPrefix . $this->getCachedFilename($id);
        if (file_exists($filename)) {
            return;
        }

        $dirname  = \dirname($filename);
        if (!is_dir($dirname)) {
            if (!mkdir($dirname, 0755, true) && !is_dir($dirname)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dirname));
            }

            chmod($dirname, 0755);
        }

        $this->makeThumbnail(
            $this->cacheFilesystemPrefix . substr($src, \strlen($this->cacheUrlPrefix)),
            $filename,
            $width,
            $height,
            $budget,
        );
    }

    public static function createImageFromFile(string $inputFilename): \GdImage
    {
        $imageInfo = getimagesize($inputFilename);
        if ($imageInfo === false) {
            throw new \RuntimeException('Unable to read image metadata.');
        }

        [$typeFlag, $typeLabel, $loader] = match ($imageInfo['mime']) {
            'image/gif' => [IMG_GIF, 'GIF', imagecreatefromgif(...)],
            'image/jpeg' => [IMG_JPG, 'JPEG', imagecreatefromjpeg(...)],
            'image/png' => [IMG_PNG, 'PNG', imagecreatefrompng(...)],
            'image/wbmp' => [IMG_WBMP, 'WBMP', imagecreatefromwbmp(...)],
            'image/webp' => [IMG_WEBP, 'WebP', imagecreatefromwebp(...)],
            'image/avif' => [IMG_AVIF, 'AVIF', imagecreatefromavif(...)],
            'image/bmp' => [IMG_BMP, 'BMP', imagecreatefrombmp(...)],
            default => throw new \RuntimeException($imageInfo['mime'] . ' images are not supported'),
        };

        if ((imagetypes() & $typeFlag) === 0) {
            throw new \RuntimeException($typeLabel . ' images are not supported');
        }

        $image = $loader($inputFilename);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Unable to decode the ' . $typeLabel . ' image.');
        }

        return $image;
    }

    /**
     * @return array<int, int>
     */
    public static function reduceSize(string $width, string $height, int $maxWidth, int $maxHeight, float $zoom = 1.0): array
    {
        if (!is_numeric($height) || !is_numeric($width)) {
            throw new \InvalidArgumentException();
        }

        $widthValue  = (float)$width;
        $heightValue = (float)$height;
        if ($widthValue <= 0.0 || $heightValue <= 0.0 || $maxWidth < 1 || $maxHeight < 1 || $zoom <= 0.0) {
            throw new \InvalidArgumentException('Image dimensions and zoom must be positive.');
        }

        if ((float)$maxWidth * $heightValue > (float)$maxHeight * $widthValue) {
            $ratio = $zoom * (float)$maxHeight / $heightValue;
        } else {
            $ratio = $zoom * (float)$maxWidth / $widthValue;
        }

        if ($ratio > 1) {
            $ratio = 1.0;
        }

        return [max(1, (int)($widthValue * $ratio)), max(1, (int)($heightValue * $ratio))];
    }

    private function getThumbnailSrc(string $src, int $newWidth, int $newHeight): string
    {
        $args = \func_get_args();
        $hash = md5(serialize($args));
        if (file_exists($this->cacheFilesystemPrefix . $this->getCachedFilename($hash))) {
            return $this->cacheUrlPrefix . $this->getCachedFilename($hash);
        }

        // No cache. Add a job to queue and fallback to original image
        $this->publisher->publish($hash, self::QUEUE_CODE, $args);

        return $src;
    }

    private function makeThumbnail(
        string               $inputFilename,
        string               $outputFilename,
        int                  $width,
        int                  $height,
        QueueExecutionBudget $budget,
    ): void
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Thumbnail dimensions must be positive.');
        }

        $budget->checkpoint(0.5);
        $image = self::createImageFromFile($inputFilename);

        $budget->checkpoint(0.25);
        $inputWidth  = imagesx($image);
        $inputHeight = imagesy($image);
        $thumbnail   = imagecreatetruecolor($width, $height);
        if (!$thumbnail instanceof \GdImage) {
            throw new \RuntimeException('Unable to create thumbnail canvas.');
        }

        $white = imagecolorallocate($thumbnail, 255, 255, 255);
        if ($white === false) {
            throw new \RuntimeException('Unable to allocate thumbnail background color.');
        }

        imagefilledrectangle($thumbnail, 0, 0, $width, $height, $white);
        imagecolortransparent($thumbnail, $white);

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $width, $height, $inputWidth, $inputHeight);

        $budget->checkpoint(0.1);
        $temporaryFilename = tempnam(\dirname($outputFilename), '.thumbnail-');
        if ($temporaryFilename === false) {
            throw new \RuntimeException('Unable to create a temporary thumbnail file.');
        }

        try {
            if (!imagejpeg($thumbnail, $temporaryFilename, 90)) {
                throw new \RuntimeException('Unable to save thumbnail: ' . $outputFilename);
            }

            if (!rename($temporaryFilename, $outputFilename)) {
                throw new \RuntimeException('Unable to publish thumbnail: ' . $outputFilename);
            }

            s2_call_without_warnings(static fn(): bool => chmod($outputFilename, 0644));
        } finally {
            if (file_exists($temporaryFilename)) {
                unlink($temporaryFilename);
            }
        }
    }

    private function getCachedFilename(string $hash): string
    {
        return self::CACHE_SUBDIRECTORY . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . substr($hash, 4) . '.jpg';
    }
}
