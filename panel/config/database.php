<?php
declare(strict_types=1);

/**
 * The panel uses NightPanel\Db (raw PDO) for every query: the mirror relies on
 * SQLite specifics (WAL, ON CONFLICT upserts, BEGIN IMMEDIATE for rate limits).
 * This connection exists only so ThinkPHP components that expect a database
 * configuration can resolve one to the same file.
 */
return [
    'default' => 'panel',
    'connections' => [
        'panel' => [
            'type' => 'sqlite',
            'database' => panel_db_file(),
            'prefix' => '',
            'debug' => false,
            'params' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ],
    ],
];
