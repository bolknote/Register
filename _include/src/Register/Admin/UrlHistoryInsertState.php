<?php

declare(strict_types = 1);

namespace Register\Admin;

/** Keeps the generated ID across commits performed by the readonly data provider. */
final class UrlHistoryInsertState
{
    public ?string $id = null;
}
