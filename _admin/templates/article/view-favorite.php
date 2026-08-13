<?php

declare(strict_types = 1);

if ($row['column_favorite']) {
    echo '<i class="icon icon-favorite-active"></i>';
} else {
    echo '<i class="icon icon-favorite-disabled"></i>';
}
