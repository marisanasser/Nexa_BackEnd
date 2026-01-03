<?php

namespace App\Http\Controllers;

use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Helpers\FileUploadHelper;
use Throwable;

class PortfolioController extends Controller
{
    const ACCEPTED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/jpg',
        'video/mp4',
        'video/quicktime',
        'video/mov',
        'video/avi',
        'video/mpeg',
        'video/x-msvideo',
        'video/webm',
        'video/ogg',
        'video/x-matroska',
        'video/x-flv',
        'video/3gpp',
        'video/x-ms-wmv',
        'application/octet-stream',
    ];

    const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024;

    const MAX_TOTAL_FILES = 200;

    public function show(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can have portfolios',
            ], 403);
        }

        $portfolio = $user->portfolio()->with(['items' => function ($query) {
            $query->orderBy('order');
        }])->first();

        if (! $portfolio) {
            return response()->json([
                'success' => true,
                'data' => [
                    'portfolio' => [
                        'id' => null,
                        'title' => null,
                        'bio' => null,
                        'profile_picture' => null,
                        'project_links' => [],
                        'items' => [],
                    ],
                    'items_count' => 0,
                    'images_count' => 0,
                    'videos_count' => 0,
                    'is_complete' => false,
                    'total_items' => 0,
                ],
            ]);
        }

        $items = $portfolio->items;
        $imageCount = $items->where('media_type', 'image')->count();
        $videoCount = $items->where('media_type', 'video')->count();
        $totalItems = $items->count();

        return response()->json([
            'success' => true,
            'data' => [
                'portfolio' => [
                    'user_id' => $portfolio->user_id,
                    'id' => $portfolio->id,
                    'title' => $portfolio->title,
                    'bio' => $portfolio->bio,
                    'profile_picture' => FileUploadHelper::resolveUrl($portfolio->profile_picture),
                    'project_links' => $portfolio->project_links ?? [],
                    'items' => $items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'title' => $item->title,
                            'file_path' => $item->file_path,
                            'file_url' => $item->file_url,
                            'media_type' => $item->media_type,
                            'order' => $item->order,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    }),
                ],
                'items_count' => $totalItems,
                'images_count' => $imageCount,
                'videos_count' => $videoCount,
                'is_complete' => ! empty($portfolio->title) && ! empty($portfolio->bio) && $totalItems >= 3,
                'total_items' => $totalItems,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can update portfolios',
            ], 403);
        }

        Log::info('Portfolio update request received', [
            'user_id' => $user->id,
            'all_data' => $request->all(),
            'has_title' => $request->has('title'),
            'has_bio' => $request->has('bio'),
            'has_profile_picture' => $request->hasFile('profile_picture'),
            'has_project_links' => $request->has('project_links'),
            'title_value' => $request->input('title'),
            'bio_value' => $request->input('bio'),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
        ]);

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:500',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'project_links' => 'nullable|string',
        ]);

        if ($request->has('project_links') && ! empty($request->input('project_links'))) {
            $projectLinks = $request->input('project_links');

            if (is_string($projectLinks)) {
                $decoded = json_decode($projectLinks, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => ['project_links' => ['Formato JSON inválido para project_links']],
                    ], 422);
                }
            }

            if (is_array($projectLinks)) {
                foreach ($projectLinks as $index => $link) {
                    if (is_array($link)) {

                        $url = trim($link['url'] ?? '');
                        if (! empty($url) && ! filter_var($url, FILTER_VALIDATE_URL)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Validation failed',
                                'errors' => ['project_links' => ["Link no índice {$index} é inválido: {$url}"]],
                            ], 422);
                        }
                    } else {

                        $url = trim($link);
                        if (! empty($url) && ! filter_var($url, FILTER_VALIDATE_URL)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Validation failed',
                                'errors' => ['project_links' => ["Link no índice {$index} é inválido: {$url}"]],
                            ], 422);
                        }
                    }
                }
            }
        }

        if ($validator->fails()) {
            Log::error('Portfolio update validation failed', [
                'user_id' => $user->id,
                'errors' => $validator->errors()->toArray(),
                'input_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {

            Log::info('CRITICAL DEBUG - User and Portfolio Check', [
                'authenticated_user_id' => $user->id,
                'authenticated_user_email' => $user->email,
                'authenticated_user_name' => $user->name,
                'user_role' => $user->role,
            ]);

            $existingPortfolio = $user->portfolio;
            if ($existingPortfolio) {
                Log::info('CRITICAL DEBUG - Existing Portfolio Found', [
                    'portfolio_id' => $existingPortfolio->id,
                    'portfolio_user_id' => $existingPortfolio->user_id,
                    'current_title' => $existingPortfolio->title,
                    'current_bio' => $existingPortfolio->bio,
                    'portfolio_created_at' => $existingPortfolio->created_at,
                    'portfolio_updated_at' => $existingPortfolio->updated_at,
                ]);
            } else {
                Log::info('CRITICAL DEBUG - No existing portfolio found, will create new one');
            }

            $portfolio = $user->portfolio()->firstOrCreate();

            Log::info('CRITICAL DEBUG - Portfolio After firstOrCreate', [
                'portfolio_id' => $portfolio->id,
                'portfolio_user_id' => $portfolio->user_id,
                'authenticated_user_id' => $user->id,
                'ownership_match' => $portfolio->user_id === $user->id,
                'current_title' => $portfolio->title,
                'current_bio' => $portfolio->bio,
            ]);

            $rawTitle = $request->input('title');
            $rawBio = $request->input('bio');

            Log::info('Raw input data', [
                'user_id' => $user->id,
                'raw_title' => $rawTitle,
                'raw_bio' => $rawBio,
                'title_type' => gettype($rawTitle),
                'bio_type' => gettype($rawBio),
            ]);

            $data = [];

            if ($request->has('title')) {
                $data['title'] = $rawTitle ?: null;
            }
            if ($request->has('bio')) {
                $data['bio'] = $rawBio ?: null;
            }

            Log::info('Updating portfolio profile', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'raw_title' => $rawTitle,
                'raw_bio' => $rawBio,
                'data_title' => $data['title'] ?? 'null',
                'data_bio' => $data['bio'] ?? 'null',
                'has_profile_picture' => $request->hasFile('profile_picture'),
                'has_project_links' => $request->has('project_links'),
                'all_request_data' => $request->all(),
            ]);

            if ($request->has('project_links')) {
                $projectLinksRaw = $request->input('project_links');

                if (is_string($projectLinksRaw) && trim($projectLinksRaw) === '') {

                } else {
                    $projectLinks = $projectLinksRaw;
                    if (is_string($projectLinks)) {
                        $decoded = json_decode($projectLinks, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $projectLinks = $decoded;
                        } else {
                            return response()->json([
                                'success' => false,
                                'message' => 'Validation failed',
                                'errors' => ['project_links' => ['Formato JSON inválido para project_links']],
                            ], 422);
                        }
                    }

                    if (is_array($projectLinks)) {
                        $validLinks = [];
                        foreach ($projectLinks as $link) {
                            if (is_array($link)) {

                                if (! empty(trim($link['title'] ?? '')) && ! empty(trim($link['url'] ?? ''))) {
                                    $validLinks[] = [
                                        'title' => trim($link['title']),
                                        'url' => trim($link['url']),
                                    ];
                                }
                            } else {

                                if (! empty(trim($link))) {
                                    $validLinks[] = [
                                        'title' => 'Link',
                                        'url' => trim($link),
                                    ];
                                }
                            }
                        }

                        $data['project_links'] = count($validLinks) > 0 ? $validLinks : [];
                    }
                }
            } else {

            }

            if ($request->hasFile('profile_picture')) {

                if ($portfolio->profile_picture) {
                    try {
                        // Use helper and wrap in try-catch to prevent update failure if delete fails
                        \App\Helpers\FileUploadHelper::delete($portfolio->profile_picture);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to delete old portfolio picture: ' . $e->getMessage());
                    }
                }

                $file = $request->file('profile_picture');
                $fileUrl = \App\Helpers\FileUploadHelper::upload($file, 'portfolio/'.$user->id);
                $data['profile_picture'] = $fileUrl;
                
                // Sync to User Avatar
                try {
                    $user->avatar_url = $fileUrl;
                    $user->save();
                } catch (Throwable $e) {
                    Log::error('Failed to sync portfolio picture to user avatar: ' . $e->getMessage());
                }
            }

            Log::info('About to update portfolio with data', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'data_to_update' => $data,
                'current_title' => $portfolio->title,
                'current_bio' => $portfolio->bio,
            ]);

            if ($portfolio->user_id !== $user->id) {
                Log::error('CRITICAL SECURITY ISSUE - Portfolio ownership mismatch', [
                    'portfolio_user_id' => $portfolio->user_id,
                    'authenticated_user_id' => $user->id,
                    'portfolio_id' => $portfolio->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Security error: Portfolio ownership mismatch',
                ], 403);
            }

            if (! empty($data)) {
                Log::info('CRITICAL DEBUG - About to update portfolio', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_user_id' => $portfolio->user_id,
                    'data_to_update' => $data,
                    'before_title' => $portfolio->title,
                    'before_bio' => $portfolio->bio,
                ]);

                $updateResult = $portfolio->update($data);

                Log::info('CRITICAL DEBUG - Portfolio update completed', [
                    'update_result' => $updateResult,
                    'portfolio_id' => $portfolio->id,
                ]);
            } else {
                Log::warning('No data to update', [
                    'user_id' => $user->id,
                    'portfolio_id' => $portfolio->id,
                    'raw_title' => $rawTitle,
                    'raw_bio' => $rawBio,
                ]);
                $updateResult = true;
            }

            Log::info('Portfolio update result', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'update_result' => $updateResult,
            ]);

            $portfolio->refresh();

            Log::info('CRITICAL VERIFICATION - Portfolio data after update', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'portfolio_user_id' => $portfolio->user_id,
                'saved_title' => $portfolio->title,
                'saved_bio' => $portfolio->bio,
                'expected_title' => $rawTitle,
                'expected_bio' => $rawBio,
                'title_match' => $portfolio->title === $rawTitle,
                'bio_match' => $portfolio->bio === $rawBio,
                'saved_project_links' => $portfolio->project_links,
                'updated_at' => $portfolio->updated_at,
            ]);

            if ($portfolio->title !== $rawTitle || $portfolio->bio !== $rawBio) {
                Log::error('CRITICAL BUG - Data mismatch after update', [
                    'expected_title' => $rawTitle,
                    'actual_title' => $portfolio->title,
                    'expected_bio' => $rawBio,
                    'actual_bio' => $portfolio->bio,
                    'portfolio_id' => $portfolio->id,
                    'user_id' => $user->id,
                ]);
            }

            Log::info('Portfolio profile updated successfully', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'saved_title' => $portfolio->title,
                'saved_bio' => $portfolio->bio,
                'saved_project_links' => $portfolio->project_links,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Portfolio updated successfully',
                'data' => [
                    'id' => $portfolio->id,
                    'user_id' => $portfolio->user_id,
                    'title' => $portfolio->title,
                    'bio' => $portfolio->bio,
                    'profile_picture' => $portfolio->profile_picture,
                    'profile_picture_url' => FileUploadHelper::resolveUrl($portfolio->profile_picture),
                    'project_links' => $portfolio->project_links ?? [],
                    'created_at' => $portfolio->created_at,
                    'updated_at' => $portfolio->updated_at,
                ],
                'user' => $user->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update portfolio profile: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update portfolio profile',
            ], 500);
        }
    }

    public function testUpdate(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $allData = $request->all();
        $titleInput = $request->input('title');
        $bioInput = $request->input('bio');
        $titleGet = $request->get('title');
        $bioGet = $request->get('bio');

        Log::info('Test portfolio update endpoint called', [
            'user_id' => $user->id,
            'all_data' => $allData,
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'has_title' => $request->has('title'),
            'has_bio' => $request->has('bio'),
            'title_input' => $titleInput,
            'bio_input' => $bioInput,
            'title_get' => $titleGet,
            'bio_get' => $bioGet,
            'request_keys' => array_keys($allData),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Test update successful',
            'data' => [
                'received_data' => $allData,
                'user_id' => $user->id,
                'has_title' => $request->has('title'),
                'has_bio' => $request->has('bio'),
                'title_input' => $titleInput,
                'bio_input' => $bioInput,
                'title_get' => $titleGet,
                'bio_get' => $bioGet,
                'request_keys' => array_keys($allData),
            ],
        ]);
    }

    public function testUpload(Request $request): JsonResponse
    {
        Log::info('Test upload request', [
            'all_data' => $request->all(),
            'files' => $request->file('files'),
            'has_files' => $request->hasFile('files'),
            'content_type' => $request->header('Content-Type'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Test successful',
            'data' => [
                'files_count' => count($request->file('files', [])),
                'has_files' => $request->hasFile('files'),
                'content_type' => $request->header('Content-Type'),
            ],
        ]);
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas criadores podem fazer upload de mídia',
            ], 403);
        }

        try {

            $uploadedFiles = $request->file('files');

            if (empty($uploadedFiles)) {
                $allFiles = $request->allFiles();
                $uploadedFiles = $allFiles['files'] ?? [];
            }

            if ($uploadedFiles && ! is_array($uploadedFiles)) {
                $uploadedFiles = [$uploadedFiles];
            }

            if (empty($uploadedFiles)) {

                $allFiles = $request->allFiles();
                Log::warning('No files found with standard methods', [
                    'user_id' => $user->id,
                    'all_files_keys' => array_keys($allFiles),
                    'has_files' => $request->hasFile('files'),
                    'content_type' => $request->header('Content-Type'),
                ]);

                if (! empty($allFiles)) {

                    foreach ($allFiles as $key => $file) {
                        if (is_array($file)) {
                            $uploadedFiles = array_merge($uploadedFiles ?? [], $file);
                        } else {
                            $uploadedFiles[] = $file;
                        }
                    }
                }
            }

            Log::info('Upload media request', [
                'user_id' => $user->id,
                'files_count' => is_array($uploadedFiles) ? count($uploadedFiles) : 0,
                'has_files' => $request->hasFile('files'),
                'all_files_keys' => array_keys($request->allFiles() ?? []),
                'files' => is_array($uploadedFiles) ? array_map(function ($file) {
                    if (! $file) {
                        return null;
                    }

                    try {
                        $fileInfo = [
                            'name' => $file->getClientOriginalName(),
                            'is_valid' => $file->isValid(),
                        ];

                        if ($file->isValid()) {
                            try {
                                $fileInfo['size'] = $file->getSize();
                            } catch (\Exception $e) {
                                $fileInfo['size_error'] = $e->getMessage();
                            }

                            try {
                                $fileInfo['mime'] = $file->getMimeType();
                            } catch (\Exception $e) {
                                $fileInfo['mime_error'] = $e->getMessage();

                                if (method_exists($file, 'getClientMimeType')) {
                                    $fileInfo['client_mime'] = $file->getClientMimeType();
                                }
                            }
                        }

                        return $fileInfo;
                    } catch (\Exception $e) {
                        return [
                            'error' => $e->getMessage(),
                            'error_class' => get_class($e),
                            'file_class' => get_class($file),
                        ];
                    }
                }, $uploadedFiles) : [],
            ]);

            Log::info('Starting file validation checks', [
                'user_id' => $user->id,
                'uploaded_files_count' => is_array($uploadedFiles) ? count($uploadedFiles) : 0,
                'uploaded_files_empty' => empty($uploadedFiles),
                'is_array' => is_array($uploadedFiles),
                'has_files_request' => $request->hasFile('files'),
            ]);

            if (empty($uploadedFiles) || (is_array($uploadedFiles) && count($uploadedFiles) === 0)) {
                Log::warning('VALIDATION FAILED: No files uploaded', [
                    'user_id' => $user->id,
                    'all_files' => $request->allFiles(),
                    'all_files_keys' => array_keys($request->allFiles() ?? []),
                    'content_type' => $request->header('Content-Type'),
                    'uploaded_files_type' => gettype($uploadedFiles),
                    'uploaded_files_value' => $uploadedFiles,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum arquivo foi enviado',
                    'errors' => ['files' => ['Nenhum arquivo foi enviado']],
                ], 422);
            }

            if (count($uploadedFiles) > 5) {
                Log::warning('VALIDATION FAILED: Too many files uploaded', [
                    'user_id' => $user->id,
                    'count' => count($uploadedFiles),
                    'max_allowed' => 5,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Máximo de 5 arquivos por upload',
                    'errors' => ['files' => ['Máximo de 5 arquivos por upload']],
                ], 422);
            }

            $portfolio = $user->portfolio()->firstOrCreate();
            $uploadedItems = [];

            foreach ($uploadedFiles as $index => $file) {

                $logMimeType = null;
                $logFileSize = null;
                $mimeTypeError = null;

                if ($file) {
                    try {
                        if ($file->isValid()) {
                            try {
                                $logFileSize = $file->getSize();
                            } catch (\Exception $e) {
                                $mimeTypeError = 'Size error: '.$e->getMessage();
                            }

                            try {
                                $logMimeType = $file->getMimeType();
                            } catch (\Exception $e) {
                                $mimeTypeError = ($mimeTypeError ? $mimeTypeError.' | ' : '').'MIME error: '.$e->getMessage();
                            }
                        }
                    } catch (\Exception $e) {
                        $mimeTypeError = 'File access error: '.$e->getMessage();
                    }
                }

                Log::info('Validating file', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'file_exists' => ! is_null($file),
                    'is_valid' => $file ? $file->isValid() : false,
                    'mime_type' => $logMimeType,
                    'file_size' => $logFileSize,
                    'mime_type_error' => $mimeTypeError,
                    'max_size' => self::MAX_FILE_SIZE,
                ]);

                Log::info('Checking file validity', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'file_exists' => ! is_null($file),
                    'file_class' => $file ? get_class($file) : null,
                    'is_valid' => $file ? $file->isValid() : false,
                ]);

                if (! $file || ! $file->isValid()) {
                    Log::error('VALIDATION FAILED: Invalid file', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'file_class' => $file ? get_class($file) : null,
                        'file_is_null' => is_null($file),
                        'is_valid' => $file ? $file->isValid() : false,
                        'original_name' => $file ? $file->getClientOriginalName() : null,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Arquivo inválido',
                        'errors' => ['files.'.$index => ['Arquivo inválido']],
                    ], 422);
                }

                try {
                    $mimeType = $file->getMimeType();
                } catch (\Exception $e) {
                    Log::error('Failed to get MIME type', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'original_name' => $file->getClientOriginalName(),
                    ]);

                    $mimeType = null;
                    if (method_exists($file, 'getClientMimeType')) {
                        $clientMimeType = $file->getClientMimeType();

                        if ($clientMimeType && in_array($clientMimeType, self::ACCEPTED_TYPES)) {
                            $mimeType = $clientMimeType;
                            Log::info('Using client MIME type as fallback', [
                                'user_id' => $user->id,
                                'index' => $index,
                                'client_mime_type' => $mimeType,
                            ]);
                        } else {
                            Log::warning('Client MIME type not in accepted types, using extension fallback', [
                                'user_id' => $user->id,
                                'index' => $index,
                                'client_mime_type' => $clientMimeType,
                                'accepted' => in_array($clientMimeType, self::ACCEPTED_TYPES),
                            ]);
                        }
                    }

                    if (! $mimeType) {
                        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
                        $mimeTypeMap = [
                            'mp4' => 'video/mp4',
                            'mov' => 'video/quicktime',
                            'avi' => 'video/x-msvideo',
                            'webm' => 'video/webm',
                            'ogg' => 'video/ogg',
                            'mkv' => 'video/x-matroska',
                            'flv' => 'video/x-flv',
                            '3gp' => 'video/3gpp',
                            'wmv' => 'video/x-ms-wmv',
                            'mpeg' => 'video/mpeg',
                            'mpg' => 'video/mpeg',
                            'jpg' => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'png' => 'image/png',
                        ];
                        $mimeType = $mimeTypeMap[$extension] ?? 'application/octet-stream';

                        Log::info('Using extension-based MIME type as fallback', [
                            'user_id' => $user->id,
                            'index' => $index,
                            'extension' => $extension,
                            'fallback_mime_type' => $mimeType,
                        ]);
                    }
                }

                Log::info('Checking MIME type against accepted types', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'mime_type' => $mimeType,
                    'mime_type_in_accepted' => in_array($mimeType, self::ACCEPTED_TYPES),
                    'accepted_types' => self::ACCEPTED_TYPES,
                ]);

                if (! in_array($mimeType, self::ACCEPTED_TYPES)) {
                    Log::error('VALIDATION FAILED: Unsupported MIME type', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'mime_type' => $mimeType,
                        'mime_type_type' => gettype($mimeType),
                        'accepted_types' => self::ACCEPTED_TYPES,
                        'original_name' => $file->getClientOriginalName(),
                        'extension' => pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Tipo de arquivo não suportado',
                        'errors' => ['files.'.$index => ['Tipo de arquivo não suportado: '.$mimeType]],
                    ], 422);
                }

                $fileSize = null;
                try {
                    $fileSize = $file->getSize();
                } catch (\Exception $e) {
                    Log::error('Failed to get file size', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('Checking file size', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'file_size' => $fileSize,
                    'max_size' => self::MAX_FILE_SIZE,
                    'size_exceeds_limit' => $fileSize ? ($fileSize > self::MAX_FILE_SIZE) : null,
                ]);

                if ($fileSize && $fileSize > self::MAX_FILE_SIZE) {
                    Log::error('VALIDATION FAILED: File too large', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'file_size' => $fileSize,
                        'max_size' => self::MAX_FILE_SIZE,
                        'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                        'max_size_gb' => round(self::MAX_FILE_SIZE / 1024 / 1024 / 1024, 2),
                    ]);
                    $maxSizeGB = self::MAX_FILE_SIZE / 1024 / 1024 / 1024;

                    return response()->json([
                        'success' => false,
                        'message' => 'Arquivo muito grande',
                        'errors' => ['files.'.$index => ['Arquivo muito grande. O tamanho máximo permitido é '.$maxSizeGB.'GB por arquivo.']],
                    ], 422);
                }

                Log::info('Determining media type', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'mime_type' => $mimeType,
                    'original_name' => $file->getClientOriginalName(),
                    'client_extension' => $file->getClientOriginalExtension(),
                ]);

                if (str_starts_with($mimeType, 'image/')) {
                    $mediaType = 'image';
                } elseif (str_starts_with($mimeType, 'video/')) {
                    $mediaType = 'video';
                } else {

                    $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
                    $videoExtensions = ['mp4', 'mov', 'avi', 'mpeg', 'mpg', 'wmv', 'webm', 'ogg', 'mkv', 'flv', '3gp'];
                    $mediaType = in_array($extension, $videoExtensions) ? 'video' : 'image';

                    Log::info('Media type determined from extension', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'extension' => $extension,
                        'media_type' => $mediaType,
                    ]);
                }

                Log::info('Media type determined', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'media_type' => $mediaType,
                ]);

                $originalExtension = $file->getClientOriginalExtension();
                $filename = time().'_'.uniqid().'.'.$originalExtension;

                Log::info('Filename generated', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'original_extension' => $originalExtension,
                    'extension_empty' => empty($originalExtension),
                    'generated_filename' => $filename,
                    'filename_length' => strlen($filename),
                ]);

                $storagePath = 'portfolio/'.$user->id;
                $fullStoragePath = storage_path('app/public/'.$storagePath);
                $directoryExists = is_dir($fullStoragePath);
                $directoryWritable = $directoryExists ? is_writable($fullStoragePath) : false;

                Log::info('Storage directory check', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'storage_path' => $storagePath,
                    'full_storage_path' => $fullStoragePath,
                    'directory_exists' => $directoryExists,
                    'directory_writable' => $directoryWritable,
                ]);

                try {
                    Log::info('Attempting to store file', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'storage_path' => $storagePath,
                        'filename' => $filename,
                        'file_size' => $file->getSize(),
                    ]);

                    $storedPath = \App\Helpers\FileUploadHelper::upload($file, $storagePath);

                    Log::info('File stored successfully', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'stored_path' => $storedPath,
                        'is_url' => true,
                    ]);
                } catch (\Exception $storageException) {
                    Log::error('File storage failed', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'error' => $storageException->getMessage(),
                        'error_file' => $storageException->getFile(),
                        'error_line' => $storageException->getLine(),
                        'trace' => $storageException->getTraceAsString(),
                    ]);
                    throw $storageException;
                }

                $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $order = $portfolio->items()->count();

                $itemData = [
                    'file_path' => $storedPath,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $mimeType,
                    'media_type' => $mediaType,
                    'file_size' => $file->getSize(),
                    'title' => $title,
                    'order' => $order,
                ];

                Log::info('Preparing to create portfolio item', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'portfolio_id' => $portfolio->id,
                    'item_data' => $itemData,
                    'file_path_length' => strlen($storedPath),
                    'file_name_length' => strlen($file->getClientOriginalName()),
                    'title_length' => strlen($title),
                ]);

                try {
                    $item = $portfolio->items()->create($itemData);

                    Log::info('Portfolio item created successfully', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'item_id' => $item->id,
                        'portfolio_id' => $item->portfolio_id,
                    ]);
                } catch (\Exception $dbException) {
                    Log::error('Database creation failed', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'portfolio_id' => $portfolio->id,
                        'error' => $dbException->getMessage(),
                        'error_code' => $dbException->getCode(),
                        'error_file' => $dbException->getFile(),
                        'error_line' => $dbException->getLine(),
                        'trace' => $dbException->getTraceAsString(),
                        'item_data' => $itemData,
                    ]);

                    try {
                        if (isset($storedPath)) {
                            \App\Helpers\FileUploadHelper::delete($storedPath);
                            Log::info('Cleaned up stored file after database failure', [
                                'user_id' => $user->id,
                                'stored_path' => $storedPath,
                            ]);
                        }
                    } catch (\Exception $cleanupException) {
                        Log::warning('Failed to cleanup stored file', [
                            'user_id' => $user->id,
                            'stored_path' => $storedPath,
                            'cleanup_error' => $cleanupException->getMessage(),
                        ]);
                    }

                    throw $dbException;
                }

                $uploadedItems[] = $item;
            }

            return response()->json([
                'success' => true,
                'message' => 'Mídia enviada com sucesso',
                'data' => [
                    'items' => collect($uploadedItems)->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'title' => $item->title,
                            'file_path' => $item->file_path,
                            'file_url' => $item->file_url,
                            'media_type' => $item->media_type,
                            'order' => $item->order,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    }),
                    'total_items' => $portfolio->items()->count(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to upload portfolio media - CRITICAL ERROR', [
                'user_id' => $user->id ?? null,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'previous_exception' => $e->getPrevious() ? [
                    'message' => $e->getPrevious()->getMessage(),
                    'file' => $e->getPrevious()->getFile(),
                    'line' => $e->getPrevious()->getLine(),
                ] : null,
                'request_data' => [
                    'files_count' => is_array($uploadedFiles ?? []) ? count($uploadedFiles) : 0,
                    'has_files' => $request->hasFile('files'),
                    'content_type' => $request->header('Content-Type'),
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ], 500);
        }
    }

    public function updateItem(Request $request, PortfolioItem $item): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can update portfolio items',
            ], 403);
        }

        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'message' => 'Students cannot update portfolio items',
            ]);
        }

        if ($item->portfolio->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $item->update($request->only(['title']));

            return response()->json([
                'success' => true,
                'message' => 'Portfolio item updated successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update portfolio item: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update portfolio item',
            ], 500);
        }
    }

    public function deleteItem(PortfolioItem $item): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can delete portfolio items',
            ], 403);
        }

        if ($item->portfolio->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        try {

            if ($item->file_path) {
                \App\Helpers\FileUploadHelper::delete($item->file_path);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Portfolio item deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete portfolio item: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete portfolio item',
            ], 500);
        }
    }

    public function reorderItems(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can reorder portfolio items',
            ], 403);
        }

        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'message' => 'Students cannot reorder portfolio items',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'item_orders' => 'required|array',
            'item_orders.*.id' => 'required|integer',
            'item_orders.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $portfolio = $user->portfolio;
            if (! $portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Portfolio not found',
                ], 404);
            }

            foreach ($request->item_orders as $itemOrder) {
                $item = $portfolio->items()->find($itemOrder['id']);
                if ($item) {
                    $item->update(['order' => $itemOrder['order']]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Portfolio items reordered successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reorder portfolio items: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder portfolio items',
            ], 500);
        }
    }

    public function statistics(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can view portfolio statistics',
            ], 403);
        }

        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_items' => 0,
                    'image_count' => 0,
                    'video_count' => 0,
                    'last_updated' => null,
                ],
            ]);
        }

        $portfolio = $user->portfolio()->with('items')->first();

        if (! $portfolio) {
            return response()->json([
                'success' => false,
                'message' => 'Portfolio not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_items' => $portfolio->items->count(),
                'image_count' => $portfolio->items->where('media_type', 'image')->count(),
                'video_count' => $portfolio->items->where('media_type', 'video')->count(),
                'last_updated' => $portfolio->updated_at,
            ],
        ]);
    }

    public function getCreatorProfile($creatorId): JsonResponse
    {
        try {

            /** @var \App\Models\User $user */
            $user = Auth::user();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $creator = User::find($creatorId);
            if (! $creator || (! $creator->isCreator() && ! $creator->isStudent())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Creator not found',
                ], 404);
            }

            $portfolio = $creator->portfolio()->with(['items' => function ($query) {
                $query->orderBy('order');
            }])->first();

            if (! $portfolio) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'creator' => [
                            'id' => $creator->id,
                            'name' => $creator->name,
                            'email' => $creator->email,
                            'avatar' => ($creator->avatar_url && $creator->avatar_url !== 'null' && $creator->avatar_url !== '') ? $creator->avatar_url : null,
                            'bio' => $creator->bio,
                            'creator_type' => $creator->creator_type,
                            'industry' => $creator->industry,
                            'niche' => $creator->niche,
                            'state' => $creator->state,
                            'gender' => $creator->gender,
                            'birth_date' => $creator->birth_date,
                            'age' => $creator->age,
                            'languages' => $creator->languages ?: ($creator->language ? [$creator->language] : []),
                            'instagram_handle' => $creator->instagram_handle,
                            'tiktok_handle' => $creator->tiktok_handle,
                            'youtube_channel' => $creator->youtube_channel,
                            'facebook_page' => $creator->facebook_page,
                            'twitter_handle' => $creator->twitter_handle,
                            'join_date' => $creator->created_at,
                            'rating' => $creator->rating ?? 0,
                            'total_reviews' => $creator->total_reviews ?? 0,
                            'total_campaigns' => $creator->total_campaigns ?? 0,
                            'completed_campaigns' => $creator->completed_campaigns ?? 0,
                        ],
                        'portfolio' => null,
                        'portfolio_items' => [],
                        'reviews' => [],
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'creator' => [
                        'id' => $creator->id,
                        'name' => $creator->name,
                        'email' => $creator->email,
                        'avatar' => ($creator->avatar_url && $creator->avatar_url !== 'null' && $creator->avatar_url !== '') ? $creator->avatar_url : null,
                        'bio' => $creator->bio,
                        'creator_type' => $creator->creator_type,
                        'industry' => $creator->industry,
                        'niche' => $creator->niche,
                        'state' => $creator->state,
                        'gender' => $creator->gender,
                        'birth_date' => $creator->birth_date,
                        'age' => $creator->age,
                        'languages' => $creator->languages ?: ($creator->language ? [$creator->language] : []),
                        'instagram_handle' => $creator->instagram_handle,
                        'tiktok_handle' => $creator->tiktok_handle,
                        'youtube_channel' => $creator->youtube_channel,
                        'facebook_page' => $creator->facebook_page,
                        'twitter_handle' => $creator->twitter_handle,
                        'join_date' => $creator->created_at,
                        'rating' => $creator->rating ?? 0,
                        'total_reviews' => $creator->total_reviews ?? 0,
                        'total_campaigns' => $creator->total_campaigns ?? 0,
                        'completed_campaigns' => $creator->completed_campaigns ?? 0,
                    ],
                    'portfolio' => [
                        'id' => $portfolio->id,
                        'title' => $portfolio->title,
                        'bio' => $portfolio->bio,
                        'profile_picture' => FileUploadHelper::resolveUrl($portfolio->profile_picture),
                        'project_links' => $portfolio->project_links ?? [],
                        'items_count' => $portfolio->items->count(),
                        'images_count' => $portfolio->items->where('media_type', 'image')->count(),
                        'videos_count' => $portfolio->items->where('media_type', 'video')->count(),
                    ],
                    'portfolio_items' => $portfolio->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'title' => $item->title,
                            'description' => $item->description,
                            'file_path' => $item->file_path,
                            'file_url' => $item->file_url,
                            'thumbnail_url' => $item->thumbnail_url,
                            'media_type' => $item->media_type,
                            'file_size' => $item->file_size,
                            'order' => $item->order,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    }),
                    'reviews' => [],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get creator profile: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get creator profile',
            ], 500);
        }
    }
}
