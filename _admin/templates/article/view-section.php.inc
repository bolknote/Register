<?php

declare(strict_types = 1);

/** @var array<string, mixed> $row */
/** @var string|null $value */
/** @var callable $trans */

$sectionLabel = $value !== null && $value !== ''
    ? $value
    : $trans(empty($row['column_parent_id']) ? 'Page root section' : 'Page section unavailable');
?>
<span><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></span>
