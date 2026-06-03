<?php

return [
    'browse' => [
        'default_page_size' => 20,
        'max_page_size' => 100,
    ],

    'installed' => [
        'update_check_batch_size' => 20,
    ],

    'modrinth' => [
        'timeout' => 5,
        'connect_timeout' => 5,
    ],

    'cache' => [
        'installed_lists_minutes' => 5,
        'metadata_minutes' => 5,
        'projects_minutes' => 30,
        'search_minutes' => 30,
        'tags_minutes' => 60,
        'versions_minutes' => 10,
    ],
];
