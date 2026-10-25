<?php

$environment = static function (string $key, string $default): string {
    $value = getenv($key);

    return false === $value || '' === $value ? $default : $value;
};
$db_host = $environment('QUEUETY_TEST_DB_HOST', '127.0.0.1');
$db_name = $environment('QUEUETY_TEST_DB_NAME', 'queuety_test');
$db_user = $environment('QUEUETY_TEST_DB_USER', 'root');
$db_pass = $environment('QUEUETY_TEST_DB_PASS', '');
$db_prefix = $environment('QUEUETY_TEST_DB_PREFIX', 'e2e_');
try {
    $pdo = new PDO('mysql:host=' . str_replace(':', ';port=', $db_host), $db_user, $db_pass);
} catch (PDOException $error) {
    echo "SKIP: MySQL not available at {$db_host}\n";
    exit(0);
}
$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $db_name) . '`');
