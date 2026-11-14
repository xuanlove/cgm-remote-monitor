<?php
declare(strict_types=1);

/**
 * File cache under runtime/. The template engine requires a cache store even
 * when template caching is disabled, and the panel has no Redis to depend on.
 */
return [
    'default' => 'file',
    'stores' => [
        'file' => [
            'type' => 'File',
            'path' => '',
            'prefix' => '',
            'expire' => 0,
            'tag_prefix' => 'tag:',
            'serialize' => [],
        ],
    ],
];
