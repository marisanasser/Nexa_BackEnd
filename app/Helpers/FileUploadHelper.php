<?php

declare(strict_types=1);

namespace App\Helpers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

class FileUploadHelper
{
    /**
     * Upload a file to the appropriate storage (GCS in production, local otherwise).
     */
    public static function upload(UploadedFile $file, string $path = 'uploads'): ?string
    {
        Log::info('FileUploadHelper::upload called', [
            'path' => $path,
            'file' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        try {
            // In Cloud Run, use GCS directly
            if (self::shouldUseGcs()) {
                Log::info('Using GCS for upload');

                return self::uploadToGcs($file, $path);
            }

            Log::info('Using local storage for upload');

            // Fallback to local storage
            return self::uploadToLocal($file, $path);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            if ($e instanceof \Google\Cloud\Core\Exception\ServiceException) {
                $errorMessage = 'GCS Service Exception: ' . $e->getMessage() . ' | Data: ' . json_encode($e->getServiceException());
            }

            Log::error('File upload failed', [
                'path' => $path,
                'file' => $file->getClientOriginalName(),
                'error_class' => get_class($e),
                'error' => $errorMessage,
                'trace' => substr($e->getTraceAsString(), 0, 1000), // Truncate trace to avoid long logs
            ]);

            // If we are using GCS and it failed, don't fallback to local in production
            // as local storage is ephemeral and will result in broken links.
            if (self::shouldUseGcs() && !app()->isLocal()) {
                throw $e; // Throw exception to be caught by controller
            }

            // Try local as last resort (only if not in production or GCS is not configured)
            try {
                Log::info('Falling back to local storage');

                return self::uploadToLocal($file, $path);
            } catch (Throwable $e2) {
                Log::error('Local upload also failed: '.$e2->getMessage());

                throw $e2;
            }
        }
    }

    /**
     * Delete a file from storage.
     */
    public static function delete(string $url): bool
    {
        try {
            // Check if it's a GCS URL
            $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'nexa-uploads-prod');
            if (str_contains($url, 'storage.googleapis.com') || (self::shouldUseGcs() && !str_starts_with($url, 'http'))) {
                return self::deleteFromGcs($url);
            }

            // Local file
            $path = str_replace('/storage/', '', $url);
            // Also handle full URLs that point to local storage
            $baseUrl = asset('storage');
            if (str_starts_with($path, $baseUrl)) {
                $path = substr($path, strlen($baseUrl));
            }
            $path = ltrim($path, '/');

            return Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            Log::warning('File delete failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Delete from GCS.
     */
    public static function deleteFromGcs(string $urlOrPath): bool
    {
        $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'nexa-uploads-prod');
        $projectId = env('GOOGLE_CLOUD_PROJECT_ID', 'nexa-teste-1');

        $objectPath = $urlOrPath;

        // Extract object path if it's a full URL
        if (str_starts_with($urlOrPath, 'http')) {
            $pattern = "/https:\\/\\/storage\\.googleapis\\.com\\/{$bucket}\\/(.+)/";
            if (preg_match($pattern, $urlOrPath, $matches)) {
                $objectPath = $matches[1];
            } else {
                // If it's a URL but doesn't match the GCS pattern, we can't delete it from GCS
                Log::warning('URL provided to deleteFromGcs does not match bucket pattern', ['url' => $urlOrPath, 'bucket' => $bucket]);
                return false;
            }
        }

        // Ensure we don't have leading slashes
        $objectPath = ltrim($objectPath, '/');

        try {
            $storage = new StorageClient([
                'projectId' => $projectId,
            ]);

            $object = $storage->bucket($bucket)->object($objectPath);
            
            if ($object->exists()) {
                $object->delete();
                Log::info('File deleted from GCS', ['path' => $objectPath]);
            } else {
                Log::warning('File not found in GCS for deletion', ['path' => $objectPath]);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('GCS delete failed', [
                'path' => $objectPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Resolve a storage path to a full URL.
     */
    public static function resolveUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        // If it's already a full URL, return it
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        // If it starts with /storage/ or storage/, it's a local path
        $cleanPath = ltrim($path, '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        // Check if we should use GCS for resolution
        // If we are in GCS mode, we might have relative paths that need to be resolved to GCS URLs
        if (self::shouldUseGcs()) {
            $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'nexa-uploads-prod');
            return "https://storage.googleapis.com/{$bucket}/" . ltrim($cleanPath, '/');
        }

        $url = asset("storage/{$cleanPath}");

        // Ensure HTTPS in production or if requested by app config
        if (!app()->isLocal()) {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }

    /**
     * Check if we should use GCS.
     */
    private static function shouldUseGcs(): bool
    {
        // Use GCS if bucket is configured and class exists
        $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET');
        $classExists = class_exists(StorageClient::class);
        $useGcs = !empty($bucket) && $classExists;

        Log::info('FileUploadHelper::shouldUseGcs check', [
            'bucket' => $bucket,
            'class_exists' => $classExists,
            'use_gcs' => $useGcs,
        ]);

        return $useGcs;
    }

    /**
     * Upload to Google Cloud Storage.
     */
    private static function uploadToGcs(UploadedFile $file, string $path): string
    {
        $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'nexa-uploads-prod');
        $projectId = env('GOOGLE_CLOUD_PROJECT_ID', 'nexa-teste-1');

        Log::info('uploadToGcs starting', [
            'bucket' => $bucket,
            'project_id' => $projectId,
            'path' => $path,
            'file' => $file->getClientOriginalName(),
            'file_path' => $file->getRealPath(),
        ]);

        $storage = new StorageClient([
            'projectId' => $projectId,
        ]);

        $gcsBucket = $storage->bucket($bucket);

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $objectPath = trim($path, '/').'/'.$filename;

        Log::info('Uploading to GCS bucket', [
            'bucket' => $bucket,
            'object_path' => $objectPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        try {
            // Note: Don't use predefinedAcl when bucket has uniform bucket-level access
            $gcsBucket->upload(
                fopen($file->getRealPath(), 'r'),
                [
                    'name' => $objectPath,
                ]
            );
        } catch (Throwable $e) {
            Log::error('GCS Upload Exception directly in uploadToGcs', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw $e;
        }

        // Return the object path instead of full URL to be consistent with uploadToLocal
        // and let resolveUrl handle it.
        Log::info('File uploaded to GCS successfully', [
            'path' => $objectPath,
        ]);

        return $objectPath;
    }

    /**
     * Upload to local storage.
     */
    private static function uploadToLocal(UploadedFile $file, string $path): string
    {
        Log::info('uploadToLocal starting', [
            'path' => $path,
            'file' => $file->getClientOriginalName(),
        ]);

        $storedPath = $file->store($path, 'public');
        $url = "/storage/{$storedPath}";

        Log::info('File uploaded to local storage', [
            'stored_path' => $storedPath,
            'url' => $url,
        ]);

        return $url;
    }
}
