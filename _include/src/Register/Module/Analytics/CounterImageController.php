<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class CounterImageController implements ControllerInterface
{
    public function __construct(
        private AnalyticsRepository $repository,
        private string              $patternFile,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => Request::METHOD_GET]);
        }

        $summary = $this->repository->pageSummary(date('Y-m-d'));
        $image   = imagecreatefrompng($this->patternFile);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Unable to load the analytics counter image.');
        }

        $black = imagecolorallocate($image, 0, 0, 0);
        if ($black === false) {
            throw new \RuntimeException('Unable to allocate the analytics counter text color.');
        }

        $values = [$summary['total'], $summary['today'], $summary['unique_today']];
        foreach ($values as $index => $value) {
            $text = (string)$value;
            imagestring($image, 1, 86 - 5 * strlen($text), 2 + 10 * $index, $text, $black);
        }

        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        if ($content === false) {
            throw new \RuntimeException('Unable to render the analytics counter image.');
        }

        return new Response($content, Response::HTTP_OK, [
            'Cache-Control'  => 'no-cache, no-store, must-revalidate',
            'Content-Length' => (string)strlen($content),
            'Content-Type'   => 'image/png',
            'Expires'        => '0',
        ]);
    }
}
