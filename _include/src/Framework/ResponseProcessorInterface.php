<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies request-specific transformations after a controller has produced its response.
 *
 * Processors run while the current request is still available on RequestStack. This makes
 * them suitable for hydrating private fragments in otherwise shareable cached responses.
 */
interface ResponseProcessorInterface
{
    public function process(Request $request, Response $response): Response;
}
