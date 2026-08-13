<?php

declare(strict_types = 1);

$data = $_POST;

if ($data === []) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

echo json_encode($data, JSON_THROW_ON_ERROR);
