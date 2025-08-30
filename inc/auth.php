<?php
    declare(strict_types=1);
    require_once __DIR__.'/../config/db.php';
    session_start();

    /**
     * Authenticate an admin user by email and password.
     *
     * The installer creates an "admins" table with columns (id, username,
     * password, email, created_at).  Passwords are stored hashed.  This
     * function looks up the admin by email and verifies the provided password.
     *
     * @param string $email    The admin email entered during login.
     * @param string $password The plaintext password to verify.
     * @return bool True on successful authentication, false otherwise.
     */
    function login(string $email, string $password): bool
    {
        $stmt = db()->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            return true;
        }
        return false;
    }

    /**
     * Log the current user out by destroying the session.
     */
    function logout(): void
    {
        session_destroy();
    }

    /**
     * Require a logged-in session, redirecting to login page if necessary.
     */
    function require_login(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /public/login.php');
            exit;
        }
    }