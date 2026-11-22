<?php
declare(strict_types=1);

return [
    // Production defaults: no stack traces in responses, no debug page.
    'app_debug' => false,
    'show_error_msg' => false,
    'default_timezone' => 'UTC',

    // Controllers are named *Controller so they never collide with the
    // NightPanel\* domain classes (NightPanel\Auth vs AuthController).
    'controller_suffix' => true,
    'empty_controller' => 'Error',

    'exception_handle' => \app\ExceptionHandle::class,

    // The panel is a single application; no multi-app directory layout.
    'auto_multi_app' => false,
    'app_express' => false,
];
