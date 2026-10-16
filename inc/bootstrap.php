<?php
/**
 * Bootstrap bersama untuk admin, API, dan CLI.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

if (!is_file(APP_ROOT . '/config.php')) {
    http_response_code(500);
    exit('config.php belum ada. Salin config.sample.php menjadi config.php.');
}
$GLOBALS['CONFIG'] = require APP_ROOT . '/config.php';
date_default_timezone_set(config('timezone', 'Asia/Jakarta'));

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/tarif.php';
require_once __DIR__ . '/qrtoken.php';
require_once __DIR__ . '/parkir.php';
require_once __DIR__ . '/ocr.php';

function config(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $offset = (new DateTime())->format('P');
        $pdo->exec("SET time_zone = '$offset'");
    }
    return $pdo;
}

/** Jalankan query berparameter dan kembalikan statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
