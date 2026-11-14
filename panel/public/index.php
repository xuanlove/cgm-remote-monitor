<?php
declare(strict_types=1);

namespace think;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();

// ThinkPHP's service discovery only scans installed packages, so the panel's
// own service (which binds the exception handler) is registered explicitly.
$app->register(\app\AppService::class);

$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
