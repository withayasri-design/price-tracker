<?php

/**
 * CSRF Protection Helper
 *
 * Handles CSRF token generation and verification.
 * Uses PHP native sessions for token storage.
 */

declare(strict_types=1);

namespace Core;

class Csrf
{
    private const TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;
    private const TOKEN_LIFETIME = 3600; // 1 hour

    /**
     * Generate a new CSRF token.
     *
     * @return string The generated token
     */
    public static function generate(): string
    {
        self::ensureSession();

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

        $_SESSION[self::TOKEN_NAME] = [
            'token' => $token,
            'expires' => time() + self::TOKEN_LIFETIME,
        ];

        return $token;
    }

    /**
     * Get the current CSRF token (generates if not exists).
     *
     * @return string The current token
     */
    public static function token(): string
    {
        self::ensureSession();

        if (!isset($_SESSION[self::TOKEN_NAME]) || self::isExpired()) {
            return self::generate();
        }

        return $_SESSION[self::TOKEN_NAME]['token'];
    }

    /**
     * Verify a CSRF token.
     *
     * @param string $token Token to verify
     * @return bool True if valid
     * @throws \Exception If token is invalid or expired
     */
    public static function verify(string $token): bool
    {
        self::ensureSession();

        if (empty($token)) {
            throw new \Exception('CSRF token is required');
        }

        if (!isset($_SESSION[self::TOKEN_NAME])) {
            throw new \Exception('CSRF token not found in session');
        }

        if (self::isExpired()) {
            self::clear();
            throw new \Exception('CSRF token has expired');
        }

        $storedToken = $_SESSION[self::TOKEN_NAME]['token'];

        if (!hash_equals($storedToken, $token)) {
            throw new \Exception('Invalid CSRF token');
        }

        return true;
    }

    /**
     * Check if a token is valid without throwing exceptions.
     *
     * @param string $token Token to check
     * @return bool True if valid
     */
    public static function check(string $token): bool
    {
        try {
            return self::verify($token);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear the current CSRF token.
     */
    public static function clear(): void
    {
        self::ensureSession();
        unset($_SESSION[self::TOKEN_NAME]);
    }

    /**
     * Regenerate the CSRF token after successful form submission.
     *
     * @return string The new token
     */
    public static function regenerate(): string
    {
        self::clear();
        return self::generate();
    }

    /**
     * Generate HTML input field for CSRF token.
     *
     * @return string HTML hidden input
     */
    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate meta tag for CSRF token (for AJAX requests).
     *
     * @return string HTML meta tag
     */
    public static function meta(): string
    {
        $token = self::token();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Verify CSRF token from request (POST or header).
     *
     * Checks in order:
     * 1. POST data: csrf_token
     * 2. Header: X-CSRF-TOKEN
     *
     * @return bool True if valid
     * @throws \Exception If token is invalid
     */
    public static function verifyRequest(): bool
    {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        return self::verify($token);
    }

    /**
     * Check if the current token is expired.
     *
     * @return bool True if expired
     */
    private static function isExpired(): bool
    {
        if (!isset($_SESSION[self::TOKEN_NAME]['expires'])) {
            return true;
        }

        return $_SESSION[self::TOKEN_NAME]['expires'] < time();
    }

    /**
     * Ensure session is started.
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Use Auth's session settings if available
            if (class_exists('Core\\Auth')) {
                Auth::startSession();
            } else {
                ini_set('session.cookie_httponly', '1');
                ini_set('session.use_strict_mode', '1');
                ini_set('session.cookie_samesite', 'Lax');
                session_start();
            }
        }
    }
}
