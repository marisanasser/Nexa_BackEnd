<?php

namespace App\Helpers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadHelper
{
    /**
     * Upload a file to the appropriate storage (GCS in production, local otherwise)
     */
    public static function upload(UploadedFile $file, string $path = 'uploads'): ?string
    {
        try {
            // In Cloud Run, use GCS directly
            if (self::shouldUseGcs()) {
                return self::uploadToGcs($file, $path);
            }
            
            // Fallback to local storage
            return self::uploadToLocal($file, $path);
        } catch (\Throwable $e) {
            Log::error('File upload failed: ' . $e->getMessage(), [
                'path' => $path,
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            // Try local as last resort
            try {
                return self::uploadToLocal($file, $path);
            } catch (\Throwable $e2) {
                Log::error('Local upload also failed: ' . $e2->getMessage());
                return null;
            }
        }
    }

    /**
     * Check if we should use GCS
     */
    private static function shouldUseGcs(): bool
    {
        return app()->environment('production') 
            && env('GOOGLE_CLOUD_STORAGE_BUCKET')
            && class_exists(StorageClient::class);
    }

    /**
     * Upload to Google Cloud Storage
     */
    private static function uploadToGcs(UploadedFile $file, string $path): string
    {
        $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'nexa-uploads-prod');
        $projectId = env('GOOGLE_CLOUD_PROJECT_ID', 'nexa-teste-1');
        
        $storage = new StorageClient([
            'projectId' => $projectId,
        ]);
        
        $gcsBucket = $storage->bucket($bucket);
        
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $objectPath = trim($path, '/') . '/' . $filename;
        
        $gcsBucket->upload(
            fopen($file->getRealPath(), 'r'),
            [
                'name' => $objectPath,
                'predefinedAcl' => 'publicRead',
            ]
        );
        
        $url = "https://storage.googleapis.com/{$bucket}/{$objectPath}";
        
        Log::info('File uploaded to GCS', [
            'path' => $objectPath,
            'url' => $url,
        ]);
        
        return $url;
    }

    /**
     * Upload to local storage
     */
    private static function uploadToLocal(UploadedFile $file, string $path): string
    {
        $storedPath = $file->store($path, 'public');
        return '/storage/' . $storedPath;
    }

    /**
     * Delete a file from storage
     */
    public static function delete(string $url): bool
    {
        try {
            if (str_contains($url, 'storage.googleapis.com')) {
                return self::deleteFromGcs($url);
            }
            
            // Local file
            $path = str_replace('/storage/', '', $url);
            return Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('File delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete from GCS
     */
    private static function deleteFromGcs(string $url): bool
    {
        $bucket = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'nexa-uploads-prod');
        $projectId = env('GOOGLE_CLOUD_PROJECT_ID', 'nexa-teste-1');
        
        // Extract object path from URL
        $pattern = "/https:\/\/storage\.googleapis\.com\/{$bucket}\/(.+)/";
        if (!preg_match($pattern, $url, $matches)) {
            return false;
        }
        
        $objectPath = $matches[1];
        
        $storage = new StorageClient([
            'projectId' => $projectId,
        ]);
        
        $object = $storage->bucket($bucket)->object($objectPath);
        $object->delete();
        
        return true;
    }
}
