<?php

declare(strict_types=1);

namespace App\Providers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use Log;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Only register GCS driver if we're in production and packages are available
        if (class_exists(StorageClient::class) && class_exists(GoogleCloudStorageAdapter::class)) {
            try {
                Storage::extend('gcs', function ($app, $config) {
                    $clientConfig = [
                        'projectId' => $config['project_id'] ?? env('GOOGLE_CLOUD_PROJECT_ID'),
                    ];

                    // Use key file if provided, otherwise use default credentials
                    if (!empty($config['key_file'])) {
                        $clientConfig['keyFile'] = $config['key_file'];
                    }

                    $storageClient = new StorageClient($clientConfig);
                    $bucket = $storageClient->bucket($config['bucket'] ?? env('GOOGLE_CLOUD_STORAGE_BUCKET'));
                    $adapter = new GoogleCloudStorageAdapter($bucket, $config['path_prefix'] ?? '');

                    return new FilesystemAdapter(
                        new Filesystem($adapter, $config),
                        $adapter,
                        $config
                    );
                });
            } catch (Throwable $e) {
                // Silently fail if GCS driver cannot be registered
                // This allows the app to work with local storage as fallback
                Log::warning('GCS driver registration failed: '.$e->getMessage());
            }
        }
    }
}
