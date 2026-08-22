<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   AdminYard
 */

declare(strict_types=1);

namespace Register\AdminYard\Event;

use Register\AdminYard\Database\Key;
use Register\AdminYard\Database\PdoDataProvider;

class AfterSaveEvent
{
    public array $ajaxExtraResponse = [];

    /**
     * @param PdoDataProvider $dataProvider
     * @param ?Key            $primaryKey Of inserted or updated entity. Null if primary key cannot be detected.
     * @param array           $context    Data passed from BeforeSaveEvent.
     */
    public function __construct(
        public readonly PdoDataProvider $dataProvider,
        public readonly ?Key            $primaryKey,
        public readonly array           $context
    ) {
    }
}
