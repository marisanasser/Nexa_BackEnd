<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Log;
use function is_array;

/**
 * CampaignFileService handles file upload and management for campaigns.
 *
 * Responsibilities:
 * - Uploading campaign images, logos, and attachments
 * - Deleting old files
 * - Managing file storage paths
 */
class CampaignFileService
{
    private const string IMAGE_PATH = 'campaigns/images';
    private const string LOGO_PATH = 'campaigns/logos';
    private const string ATTACHMENT_PATH = 'campaigns/attachments';

    /**
     * Upload a campaign image.
     */
    public function uploadImage(UploadedFile $file): string
    {
        return $this->uploadFile($file, self::IMAGE_PATH);
    }

    /**
     * Upload a campaign logo.
     */
    public function uploadLogo(UploadedFile $file): string
    {
        return $this->uploadFile($file, self::LOGO_PATH);
    }

    /**
     * Upload campaign attachments.
     *
     * @param array|UploadedFile $files
     */
    public function uploadAttachments($files): array|string
    {
        if (!is_array($files)) {
            return $this->uploadFile($files, self::ATTACHMENT_PATH);
        }

        $urls = [];
        foreach ($files as $file) {
            $urls[] = $this->uploadFile($file, self::ATTACHMENT_PATH);
        }

        return $urls;
    }

    /**
     * Upload a file to storage.
     */
    public function uploadFile(UploadedFile $file, string $path): string
    {
        $fileName = $this->generateFileName($file);
        $filePath = $file->storeAs($path, $fileName, config('filesystems.default'));

        return Storage::url($filePath);
    }

    /**
     * Delete a file from storage.
     */
    public function deleteFile(?string $fileUrl): bool
    {
        if (!$fileUrl) {
            return true;
        }

        try {
            $path = $this->extractPathFromUrl($fileUrl);

            if (Storage::disk(config('filesystems.default'))->exists($path)) {
                Storage::disk(config('filesystems.default'))->delete($path);

                Log::info('File deleted', ['path' => $path]);

                return true;
            }

            return true; // File doesn't exist, consider it deleted
        } catch (Exception $e) {
            Log::warning('Failed to delete file', [
                'url' => $fileUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete multiple files.
     */
    public function deleteFiles(array $fileUrls): void
    {
        foreach ($fileUrls as $url) {
            $this->deleteFile($url);
        }
    }

    /**
     * Delete all files associated with a campaign.
     */
    public function deleteCampaignFiles(
        ?string $imageUrl,
        ?string $logoUrl,
        array|string|null $attachments
    ): void {
        $this->deleteFile($imageUrl);
        $this->deleteFile($logoUrl);

        if ($attachments) {
            if (is_array($attachments)) {
                $this->deleteFiles($attachments);
            } else {
                $this->deleteFile($attachments);
            }
        }
    }

    /**
     * Process file uploads for campaign update.
     *
     * @return array{data: array, uploaded_files: array, old_files: array}
     */
    public function processUpdateFiles(
        array $request,
        ?string $currentImageUrl,
        ?string $currentLogoUrl,
        array|string|null $currentAttachments
    ): array {
        $data = [];
        $uploadedFiles = ['image' => null, 'logo' => null, 'attachments' => []];
        $oldFiles = ['image' => null, 'logo' => null, 'attachments' => []];

        // Process image
        if (isset($request['image']) && $request['image'] instanceof UploadedFile) {
            $newUrl = $this->uploadImage($request['image']);
            $data['image_url'] = $newUrl;
            $uploadedFiles['image'] = $newUrl;
            $oldFiles['image'] = $currentImageUrl;
        }

        // Process logo
        if (isset($request['logo']) && $request['logo'] instanceof UploadedFile) {
            $newUrl = $this->uploadLogo($request['logo']);
            $data['logo'] = $newUrl;
            $uploadedFiles['logo'] = $newUrl;
            $oldFiles['logo'] = $currentLogoUrl;
        }

        // Process attachments
        if (isset($request['attach_file'])) {
            $files = $request['attach_file'];
            if (!is_array($files)) {
                $files = [$files];
            }

            $newUrls = [];
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $url = $this->uploadFile($file, self::ATTACHMENT_PATH);
                    $newUrls[] = $url;
                    $uploadedFiles['attachments'][] = $url;
                }
            }

            if (!empty($newUrls)) {
                $data['attach_file'] = $newUrls;
                $oldFiles['attachments'] = is_array($currentAttachments)
                    ? $currentAttachments
                    : ($currentAttachments ? [$currentAttachments] : []);
            }
        }

        return [
            'data' => $data,
            'uploaded_files' => $uploadedFiles,
            'old_files' => $oldFiles,
        ];
    }

    /**
     * Rollback uploaded files (for use when transaction fails).
     */
    public function rollbackUploadedFiles(array $uploadedFiles): void
    {
        if ($uploadedFiles['image']) {
            $this->deleteFile($uploadedFiles['image']);
        }

        if ($uploadedFiles['logo']) {
            $this->deleteFile($uploadedFiles['logo']);
        }

        if (!empty($uploadedFiles['attachments'])) {
            $this->deleteFiles($uploadedFiles['attachments']);
        }
    }

    /**
     * Clean up old files after successful update.
     */
    public function cleanupOldFiles(array $oldFiles): void
    {
        if ($oldFiles['image']) {
            $this->deleteFile($oldFiles['image']);
        }

        if ($oldFiles['logo']) {
            $this->deleteFile($oldFiles['logo']);
        }

        if (!empty($oldFiles['attachments'])) {
            $this->deleteFiles($oldFiles['attachments']);
        }
    }

    /**
     * Generate a unique filename.
     */
    private function generateFileName(UploadedFile $file): string
    {
        return time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
    }

    /**
     * Extract storage path from URL.
     */
    private function extractPathFromUrl(string $url): string
    {
        return str_replace('/storage/', '', $url);
    }
}
