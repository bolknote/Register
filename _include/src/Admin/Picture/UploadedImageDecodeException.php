<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Picture;

/** The upload matches a passive image MIME type but this PHP build cannot decode it. */
final class UploadedImageDecodeException extends \RuntimeException
{
}
