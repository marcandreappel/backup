<?php
return [
    'exclude_directories' => [
        'vendor/',
        'npm_modules/',
    ],
    'disk'                => 'local',
    'database'            => 'mysql',
    'base_name'           => 'backups',
    'base_path'           => base_path(),
    'temp_path'           => storage_path(),
    'parts_size'          => '750m',
];
