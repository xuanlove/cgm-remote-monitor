<?php
declare(strict_types=1);

namespace app;

use think\exception\Handle;
use think\Service;

/**
 * Application service.
 *
 * Two jobs:
 *  - bind the panel's exception handler, so failures render the panel's own
 *    error pages instead of ThinkPHP's debug screen;
 *  - nothing else: sessions start and the bootstrap administrator is created
 *    in the HTTP-only StartSession middleware, so console commands never
 *    mutate user accounts.
 */
class AppService extends Service
{
    public function register(): void
    {
        $this->app->bind(Handle::class, ExceptionHandle::class);
    }
}
