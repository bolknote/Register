<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\AdminYard\Form;

use Register\AdminYard\Form\Datetime;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomDateTime extends Datetime
{
    public function __construct(string $fieldName, private readonly TranslatorInterface $translator)
    {
        parent::__construct($fieldName);
    }

    #[\Override]
    public function getHtml(?string $id = null): string
    {
        $id ??= uniqid('datetime-', true);

        /**
         * Hack to set the current server time in JS. We pass the current time
         * in the server's timezone to $serverTime with a formally assigned UTC timezone.
         *
         * This way, timeDifference will contain the client's time offset in seconds relative to UTC,
         * adjusted for the client's clock inaccuracy.
         *
         * Additionally, on the client side, toISOString() returns the date and time converted in the UTC timezone
         * regardless of the client's timezone. Because of this, the time offset relative to UTC disappears,
         * and the client's clock inaccuracy cancels out.
         */
        $serverTime = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

        $trans      = htmlspecialchars($this->translator->trans('Now'), ENT_QUOTES, 'UTF-8');
        $safeId     = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $serverTime = htmlspecialchars($serverTime, ENT_QUOTES, 'UTF-8');

        $script = <<<HTML
    <a
        href="#"
        class="now-control"
        data-target-id="$safeId"
        data-server-time="$serverTime"
        id="$safeId-now-control">$trans</a>
    HTML;

        return parent::getHtml($id) . $script;
    }
}
