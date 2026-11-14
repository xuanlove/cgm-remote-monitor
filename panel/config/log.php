<?php
declare(strict_types=1);

return [
    'default' => 'file',
    'channels' => [
        'file' => [
            'type' => 'File',
            'path' => '',
            'level' => ['error', 'warning'],
            'apart_level' => [],
            'max_files' => 14,
            'json' => false,
        ],
    ],
];
