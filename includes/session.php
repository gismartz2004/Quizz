<?php
/**
 * includes/session.php
 * Centralized session configuration to ensure a consistent 2-hour timeout
 */

// 1. Set session lifetime (sec) - 2 hours
$session_lifetime = 7200;

// 2. Set INI params BEFORE starting session
ini_set('session.gc_maxlifetime', $session_lifetime);
ini_set('session.cookie_lifetime', $session_lifetime);

// 3. Set cookie params (secure, httponly if possible)
// Using array signature for PHP 7.3+ compatibility
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

// 4. Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
