<?php
/**
 * Interface for controllers to be used in the Application.
 *
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface ControllerInterface
{
    public function handle(Request $request): Response;
}
