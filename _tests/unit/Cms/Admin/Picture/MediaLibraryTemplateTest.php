<?php

declare(strict_types = 1);

namespace unit\Cms\Admin\Picture;

use Codeception\Test\Unit;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Model\PermissionChecker;

final class MediaLibraryTemplateTest extends Unit
{
    public function testFileSearchAndTypeFilterAreVisibleWithoutOpeningSettings(): void
    {
        $output = $this->render([]);

        self::assertStringContainsString('type="search" data-media-search', $output);
        self::assertStringContainsString('<select data-media-type>', $output);
        self::assertStringContainsString('data-media-folder-path', $output);
        self::assertStringContainsString('data-media-count role="status"', $output);
        self::assertStringContainsString('data-media-empty hidden', $output);
        self::assertStringContainsString('data-media-no-matches hidden', $output);
        self::assertStringContainsString('/_admin/css/media-library.css?v=test', $output);
    }

    public function testViewingFilesDoesNotExposeUploadOrMutationActions(): void
    {
        $output = $this->render([]);

        self::assertStringNotContainsString('id="uploadForm"', $output);
        self::assertStringNotContainsString('data-media-rename', $output);
        self::assertStringNotContainsString('data-media-delete', $output);
        self::assertStringContainsString('Media empty folder browse hint', $output);
    }

    public function testUploadAndFileMutationPermissionsStaySeparate(): void
    {
        $uploader = $this->render([PermissionChecker::PERMISSION_CREATE_ARTICLES]);
        self::assertStringContainsString('id="uploadForm"', $uploader);
        self::assertStringContainsString('Media empty folder hint', $uploader);
        self::assertStringNotContainsString('data-media-delete', $uploader);

        $manager = $this->render([PermissionChecker::PERMISSION_EDIT_SITE]);
        self::assertStringNotContainsString('id="uploadForm"', $manager);
        self::assertStringContainsString('data-media-rename', $manager);
        self::assertStringContainsString('data-media-delete', $manager);
    }

    /** @param list<string> $permissions */
    private function render(array $permissions): string
    {
        return (new TemplateRenderer(new Translator([], 'en')))->render(
            dirname(__DIR__, 5) . '/_admin/templates/picture-manager-content.php.inc',
            [
                'basePath' => '',
                'locale' => 'en',
                'isGranted' => static fn(string $permission): bool => \in_array($permission, $permissions, true),
                'friendlyFilesize' => static fn(int $size): string => (string)$size,
                'adminAssetVersion' => static fn(): string => 'test',
                'imagePath' => '/_pictures',
                'standalone' => false,
            ],
        );
    }
}
