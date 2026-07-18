<?php

/**
 * Authentication Helper
 *
 * Handles user authentication, session management, and access control.
 * Uses PHP native sessions with password_hash/password_verify.
 */

declare(strict_types=1);

namespace Core;

class Auth
{
    private static bool $sessionStarted = false;

    /**
     * Start session if not already started.
     */
    public static function startSession(): void
    {
        if (self::$sessionStarted) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            // Secure session settings
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_samesite', 'Lax');

            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', '1');
            }

            session_start();
        }

        self::$sessionStarted = true;
    }

    /**
     * Log in a user.
     *
     * @param int $userId
     * @param string $email
     * @param string $role
     * @param string $fullName
     */
    public static function login(int $userId, string $email, string $role, string $fullName): void
    {
        self::startSession();

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['email'] = $email;
        $_SESSION['role'] = $role;
        $_SESSION['full_name'] = $fullName;
        $_SESSION['logged_in_at'] = time();
    }

    /**
     * Log out the current user.
     */
    public static function logout(): void
    {
        self::startSession();

        // Clear session data
        $_SESSION = [];

        // Delete session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        self::$sessionStarted = false;
    }

    /**
     * Check if user is logged in.
     */
    public static function check(): bool
    {
        self::startSession();
        return isset($_SESSION['user_id']);
    }

    /**
     * Get current user ID.
     */
    public static function userId(): ?int
    {
        self::startSession();
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user's role.
     */
    public static function role(): ?string
    {
        self::startSession();
        return $_SESSION['role'] ?? null;
    }

    /**
     * Check if current user is admin.
     */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /**
     * Get current user's full name.
     */
    public static function fullName(): ?string
    {
        self::startSession();
        return $_SESSION['full_name'] ?? null;
    }

    /**
     * Get current user's email.
     */
    public static function email(): ?string
    {
        self::startSession();
        return $_SESSION['email'] ?? null;
    }

    /**
     * Get all current user data.
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'user_id' => $_SESSION['user_id'],
            'email' => $_SESSION['email'],
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name'],
            'logged_in_at' => $_SESSION['logged_in_at'] ?? null,
        ];
    }

    /**
     * Require user to be logged in. Redirects to login if not.
     *
     * @param string|null $redirectUrl URL to redirect to if not logged in (null = auto-detect)
     */
    public static function requireLogin(?string $redirectUrl = null): void
    {
        if (!self::check()) {
            // Store intended URL for redirect after login
            self::startSession();
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';

            // Auto-detect login page path based on current script location
            if ($redirectUrl === null) {
                $redirectUrl = self::getRelativePath('pages/login.php');
            }

            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    /**
     * Require user to be an admin. Redirects or shows 403 if not.
     *
     * @param string|null $redirectUrl URL to redirect to if not admin (null = auto-detect)
     */
    public static function requireAdmin(?string $redirectUrl = null): void
    {
        if ($redirectUrl === null) {
            $redirectUrl = self::getRelativePath('pages/login.php');
        }
        self::requireLogin($redirectUrl);

        if (!self::isAdmin()) {
            http_response_code(403);
            die('Access denied. Admin privileges required.');
        }
    }

    /**
     * Get relative path from current script to target file.
     *
     * @param string $targetPath Target path from project root (e.g., 'pages/login.php')
     * @return string Relative path
     */
    private static function getRelativePath(string $targetPath): string
    {
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $depth = substr_count(trim($scriptPath, '/'), '/');

        // If we're in a subdirectory like /price-tracker/pages/, we need to go up
        // Count how many levels deep we are from the project root
        if (strpos($scriptPath, '/pages/admin') !== false) {
            return '../../' . $targetPath;
        } elseif (strpos($scriptPath, '/pages') !== false || strpos($scriptPath, '/api') !== false) {
            return '../' . $targetPath;
        } elseif (strpos($scriptPath, '/admin') !== false) {
            return '../' . $targetPath;
        }

        return $targetPath;
    }

    /**
     * Get and clear the redirect URL stored before login.
     */
    public static function getRedirectAfterLogin(): string
    {
        self::startSession();
        $url = $_SESSION['redirect_after_login'] ?? self::getRelativePath('pages/dashboard.php');
        unset($_SESSION['redirect_after_login']);
        return $url;
    }

    /**
     * Hash a password securely.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /**
     * Verify a password against a hash.
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if a password hash needs to be rehashed.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /**
     * Set a flash message to display on next request.
     */
    public static function flash(string $key, string $message): void
    {
        self::startSession();
        $_SESSION['flash'][$key] = $message;
    }

    /**
     * Get and clear a flash message.
     */
    public static function getFlash(string $key): ?string
    {
        self::startSession();
        $message = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $message;
    }

    /**
     * Check if a flash message exists.
     */
    public static function hasFlash(string $key): bool
    {
        self::startSession();
        return isset($_SESSION['flash'][$key]);
    }
}
