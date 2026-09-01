<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * The two disks the application actually uses.
         *
         * Both are driver-agnostic: VERITAS_STORAGE_DRIVER flips them from
         * the local filesystem to S3-compatible object storage without a
         * line of application code changing. In production both point at
         * the same bucket family with different prefixes and different
         * public exposure.
         */
        'media' => [
            'driver' => env('VERITAS_STORAGE_DRIVER', 'local'),
            'root' => storage_path('app/public/media'),
            'url' => rtrim((string) env('VERITAS_MEDIA_URL', env('APP_URL', 'http://localhost').'/storage/media'), '/'),
            'visibility' => 'public',
            'key' => env('VERITAS_STORAGE_KEY'),
            'secret' => env('VERITAS_STORAGE_SECRET'),
            'region' => env('VERITAS_STORAGE_REGION', 'auto'),
            'bucket' => env('VERITAS_MEDIA_BUCKET'),
            'endpoint' => env('VERITAS_STORAGE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('VERITAS_STORAGE_PATH_STYLE', false),
            'throw' => true,
            'report' => false,
        ],

        /*
         * Seller paperwork. No 'url' and no public visibility, on purpose:
         * there is nothing to hotlink and no route that serves it. Reads
         * go through an authorisation check every time.
         */
        'documents' => [
            'driver' => env('VERITAS_STORAGE_DRIVER', 'local'),
            'root' => storage_path('app/private/documents'),
            'visibility' => 'private',
            'serve' => false,
            'key' => env('VERITAS_STORAGE_KEY'),
            'secret' => env('VERITAS_STORAGE_SECRET'),
            'region' => env('VERITAS_STORAGE_REGION', 'auto'),
            'bucket' => env('VERITAS_DOCUMENT_BUCKET'),
            'endpoint' => env('VERITAS_STORAGE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('VERITAS_STORAGE_PATH_STYLE', false),
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
