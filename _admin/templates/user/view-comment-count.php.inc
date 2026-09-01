<?php

declare(strict_types = 1);

/** @var int|string|null $value */

echo htmlspecialchars((string)(int)$value, ENT_QUOTES, 'UTF-8');
