<?php

return [
    // All directories not to be included in the backup
    'exclude_directories' => [
        'vendor/',
        'npm_modules/',
        // ...
    ],
    // The disk to be used, can be as well 's3' or 'b2', etc
    'disk'         => 'local',
    // Database type
    'database'     => 'mysql',
    // The name of the backup on the disk
    'base_name'    => 'backups',
    // The directory from where to do the backup
    'base_path'    => base_path(),
    // Where to put the temporary files (needs to be almost as large as the project to back-up)
    'temp_path'    => storage_path(),
    // What size should the ZIP parts be
    'parts_size'   => '500m',
    // How many backups to keep on the disk
    'backup_count' => 7,
];
