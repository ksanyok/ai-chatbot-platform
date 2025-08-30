<?php
declare(strict_types=1);
require_once __DIR__.'/../config/db.php';
session_start();

function login(string $email, string $password): bool
{
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['pass_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        return true;
    }
    return false;
}

function logout(): void
{
    session_destroy();
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /public/login.php');
        exit;
    }
}