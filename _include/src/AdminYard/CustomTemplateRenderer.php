<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard;

use S2\AdminYard\TemplateRenderer;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\Model\PermissionChecker;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomTemplateRenderer extends TemplateRenderer implements StatefulServiceInterface
{
    private const array ADMIN_YARD_TEMPLATE_OVERRIDES = [
        'edit.php.inc'             => '_admin/templates/admin-yard/edit.php.inc',
        'inline_form_cell.php.inc' => '_admin/templates/admin-yard/inline_form_cell.php.inc',
        'list.php.inc'             => '_admin/templates/admin-yard/list.php.inc',
        'list-actions.php.inc'     => '_admin/templates/admin-yard/list-actions.php.inc',
        'show.php.inc'             => '_admin/templates/admin-yard/show.php.inc',
    ];

    /**
     * @var array<mixed>|null
     */
    private ?array $extraAssets = null;

    public function __construct(
        TranslatorInterface                       $translator,
        private readonly DynamicConfigProvider    $dynamicConfigProvider,
        private readonly PermissionChecker        $permissionChecker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string                   $basePath,
        private readonly string                   $rootDir,
    ) {
        parent::__construct($translator);
    }

    private const array FILE_SIZE_UNITS = ['B', 'КB', 'MB', 'GB', 'ТB', 'PB', 'EB', 'ZB', 'YB'];

    /**
     * @param array<mixed> $data
     */
    #[\Override]
    public function render(string $_template_path, array $data = []): string
    {
        $_template_path   = $this->resolveTemplateOverride($_template_path);
        $trans             = $this->translator->trans(...);
        $locale            = $this->translator->getLocale();
        $param             = $this->dynamicConfigProvider->get(...);
        $isGranted         = $this->permissionChecker->isGranted(...);
        $friendlyFilesize  = $this->friendlyFilesize(...);
        $numberFormat      = $this->numberFormat(...);
        $styleColorScheme  = $this->styleColorScheme(...);
        $adminStyleVersion = $this->adminStyleVersion(...);
        $adminAssetVersion = $this->adminAssetVersion(...);
        $basePath          = $this->basePath;
        [$extraStyles, $extraScripts] = $this->getExtraAssets();

        // Template data must not be able to replace the selected file or renderer helpers.
        extract($data, EXTR_SKIP);
        ob_start();
        if ($_template_path[0] === '/' || $_template_path[0] === '.') {
            require $_template_path;
        } else {
            require $this->rootDir . $_template_path;
        }

        $output = ob_get_clean();
        if ($output === false) {
            throw new \RuntimeException('Unable to render template "' . $_template_path . '".');
        }

        return $output;
    }

    private function resolveTemplateOverride(string $templatePath): string
    {
        $realPath = realpath($templatePath);
        if ($realPath === false) {
            return $templatePath;
        }

        $normalizedPath = str_replace('\\', '/', $realPath);
        if (!str_ends_with(dirname($normalizedPath), '/s2/admin-yard/templates')) {
            return $templatePath;
        }

        $override = self::ADMIN_YARD_TEMPLATE_OVERRIDES[basename($normalizedPath)] ?? null;

        return $override === null ? $templatePath : $this->rootDir . $override;
    }

    public function friendlyFilesize(int $size): string
    {
        $unitIndex  = 0;
        $displaySize = (float)$size;
        $unitsNum   = \count(self::FILE_SIZE_UNITS);
        while ($displaySize / 1024.0 > 1.0 && $unitIndex < $unitsNum - 1) {
            $displaySize /= 1024.0;
            ++$unitIndex;
        }

        $unit = match ($unitIndex) {
            0 => self::FILE_SIZE_UNITS[0],
            1 => self::FILE_SIZE_UNITS[1],
            2 => self::FILE_SIZE_UNITS[2],
            3 => self::FILE_SIZE_UNITS[3],
            4 => self::FILE_SIZE_UNITS[4],
            5 => self::FILE_SIZE_UNITS[5],
            6 => self::FILE_SIZE_UNITS[6],
            7 => self::FILE_SIZE_UNITS[7],
            8 => self::FILE_SIZE_UNITS[8],
        };

        return $this->translator->trans('Filesize format', [
            '{{ number }}' => $this->numberFormat($displaySize),
            '{{ unit }}'   => $this->translator->trans('Filesize ' . $unit),
        ]);
    }

    private function numberFormat(int|float $number, bool $keepTrailingZero = false, ?int $decimalCount = null): string
    {
        $result = number_format(
            $number,
            $decimalCount ?? (int)$this->translator->trans('Decimal count'),
            $this->translator->trans('Decimal point'),
            $this->translator->trans('Thousands separator')
        );

        if (!$keepTrailingZero) {
            return preg_replace('#' . preg_quote($this->translator->trans('Decimal point'), '#') . '?0*$#', '', $result)
                ?? throw new \RuntimeException('Unable to format a number.');
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function getExtraAssets(): array
    {
        if ($this->extraAssets !== null) {
            return $this->extraAssets;
        }

        $event = new CustomTemplateRendererEvent($this->basePath);
        $this->eventDispatcher->dispatch($event);

        return $this->extraAssets = [$event->extraStyles, $event->extraScripts];
    }

    private function styleColorScheme(): string
    {
        $styleName = $this->dynamicConfigProvider->get('S2_STYLE');
        if (!\is_string($styleName) || preg_match('#\A[0-9a-zA-Z_-]+\z#D', $styleName) !== 1) {
            throw new \LogicException('The selected style name is invalid.');
        }

        $styleFilename = $this->rootDir . '_styles/' . $styleName . '/' . $styleName . '.php';
        if (!is_file($styleFilename)) {
            throw new \LogicException('The selected style definition does not exist.');
        }

        $assetPack = require $styleFilename;
        if (!$assetPack instanceof AssetPack) {
            throw new \LogicException('The selected style definition must return an AssetPack object.');
        }

        return $assetPack->getColorScheme();
    }

    private function adminStyleVersion(): string
    {
        return $this->adminAssetVersion('_admin/css/register.css');
    }

    private function adminAssetVersion(string $assetPath): string
    {
        if (!str_starts_with($assetPath, '_admin/') || str_contains($assetPath, '..')) {
            throw new \InvalidArgumentException('Only administration assets can be versioned.');
        }

        $filename = $this->rootDir . $assetPath;
        if (!\is_file($filename)) {
            throw new \LogicException(sprintf('The administration asset "%s" does not exist.', $assetPath));
        }

        $modifiedAt = \filemtime($filename);
        if ($modifiedAt === false) {
            throw new \LogicException(sprintf('Unable to read the modification time of "%s".', $assetPath));
        }

        return (string)$modifiedAt;
    }

    #[\Override]
    public function clearState(): void
    {
        $this->extraAssets = null;
    }
}
