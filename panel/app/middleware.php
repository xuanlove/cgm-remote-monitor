<?php
declare(strict_types=1);

/**
 * Global middleware, run for every routed request in this order.
 *
 * Authenticate / AdminOnly / CsrfGuard / Throttle are attached per route: a
 * global CSRF check would turn unknown POSTs from 404 into 403 and would block
 * the GET /logout probe that has to answer 405.
 */
return [
    \app\middleware\SecurityHeaders::class,
    \app\middleware\NormalizePath::class,
    \app\middleware\StartSession::class,
    \app\middleware\ViewGlobals::class,
];
