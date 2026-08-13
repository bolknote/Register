<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package S2
 */

declare(strict_types = 1);

namespace Helper;

use Codeception\Module;
use Codeception\Module\PhpBrowser;
use Codeception\Lib\Connector\Guzzle;

class Acceptance extends Module
{
    /** @return array<string, list<string>> */
    public function grabHeaders(): array
    {
        /** @var PhpBrowser $browser */
        $browser = $this->getModule('PhpBrowser');

        $client = $browser->client;
        if (!$client instanceof Guzzle) {
            throw new \LogicException('The PhpBrowser connector has not been initialized.');
        }

        return $client->getInternalResponse()->getHeaders();
    }
}
