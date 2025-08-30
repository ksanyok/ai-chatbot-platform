<?php
declare(strict_types=1);

$db = null;

function db(): PDO
{
    global $db;
    if ($db) return $db;

    $host = 'kidsdan.mysql.tools';
    $name = 'kidsdan_apibot';
    $user = 'kidsdan_apibot';
    $pass = '5v!3#V3tUv';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$name;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $db = new PDO($dsn, $user, $pass, $options);
    return $db;
}