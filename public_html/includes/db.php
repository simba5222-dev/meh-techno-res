<?php
/**
 * Подключение к базе данных через PDO.
 */

require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                die('Ошибка подключения к базе данных: ' . htmlspecialchars($e->getMessage()));
            }
            die('Не удалось подключиться к базе данных. Обратитесь к администратору.');
        }
    }

    return $pdo;
}
