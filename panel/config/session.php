<?php
declare(strict_types=1);

/**
 * Documented for completeness only: the panel starts the native PHP session in
 * app\middleware\StartSession so it can reproduce the exact cookie flags
 * (name, HttpOnly, SameSite=Lax, proxy-aware Secure) the audit required.
 * ThinkPHP's own session middleware deliberately stays disabled.
 */
return [
    'name' => 'nightpanel',
    'type' => 'file',
    'store' => null,
    'expire' => 0,
    'prefix' => '',
    'auto_start' => false,
    'httponly' => true,
    'secure' => false,
    'samesite' => 'Lax',
];
