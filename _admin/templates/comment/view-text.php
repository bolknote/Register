<?php

declare(strict_types = 1);

/** @var array<string, mixed> $row */
/** @var callable $trans */

echo \Register\Core\Comment\CommentHtml::render((string)$row['column_text'], $trans('wrote'));
