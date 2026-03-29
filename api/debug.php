<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/config.php';
    echo json_encode(['ok' => true, 'session' => $_SESSION ?? [], 'php' => PHP_VERSION]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
