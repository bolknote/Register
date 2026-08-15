<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use S2\Rose\Entity\Indexable;

/** Makes search-algorithm changes visible to Rose even when document content is unchanged. */
final class SearchIndexable extends Indexable
{
    private const string INDEX_FORMAT_VERSION = 'opencorpora-ru-2.4.417150.4580142-prereform-ru-v1';

    #[\Override]
    public function calcHash(): string
    {
        return md5(parent::calcHash() . "\0" . self::INDEX_FORMAT_VERSION);
    }
}
