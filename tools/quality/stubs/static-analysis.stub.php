<?php

namespace Register\AdminYard\Config;

readonly abstract class AbstractFieldType
{
}

readonly class DbColumnFieldType extends AbstractFieldType
{
    /** @param mixed $defaultValue */
    public function __construct(
        public string $dataType = '',
        public bool $primaryKey = false,
        public mixed $defaultValue = null,
    ) {
    }
}
