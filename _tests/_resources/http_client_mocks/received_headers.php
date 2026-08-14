<?php

declare(strict_types = 1);

header('Content-Type: application/json');

echo json_encode([
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'cookie'        => $_SERVER['HTTP_COOKIE'] ?? null,
    'x_test'        => $_SERVER['HTTP_X_TEST'] ?? null,
], JSON_THROW_ON_ERROR);
