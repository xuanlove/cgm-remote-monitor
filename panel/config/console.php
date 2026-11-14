<?php
declare(strict_types=1);

return [
    'commands' => [
        'sync' => \app\command\SyncCommand::class,
        'cleanup' => \app\command\CleanupCommand::class,
        'health' => \app\command\HealthCommand::class,
    ],
];
