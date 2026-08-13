<?php

namespace S2\AdminYard\Config;

class DbColumnFieldType
{
    /** @param mixed $defaultValue */
    public function __construct(
        public string $dataType = '',
        public bool $primaryKey = false,
        public mixed $defaultValue = null,
    ) {
    }
}
