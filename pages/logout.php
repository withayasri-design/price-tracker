<?php

/**
 * Logout Handler
 *
 * Destroys session and redirects to login page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;

Auth::logout();

header('Location: /pages/login.php');
exit;
