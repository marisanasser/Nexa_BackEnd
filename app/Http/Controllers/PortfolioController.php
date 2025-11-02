<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

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
        'application/octet-stream' // Fallback for files where MIME type detection fails
    ];
    
    const MAX_FILE_SIZE =  200 * 1024 * 1024; // 200MB 
    const MAX_TOTAL_FILES = 200; // 200 files

    /**
     * Get portfolio data
     */
    public function show(): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can have portfolios'
            ], 403);
        }

        // Students can have portfolios as well

        $portfolio = $user->portfolio()->with(['items' => function ($query) {
            $query->orderBy('order');
        }])->first();

        if (!$portfolio) {
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
                    'total_items' => 0
                ]
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
                    'profile_picture' => $portfolio->profile_picture ? asset('storage/' . $portfolio->profile_picture) : null,
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
                'is_complete' => !empty($portfolio->title) && !empty($portfolio->bio) && $totalItems >= 3,
                'total_items' => $totalItems
            ]
        ]);
    }

    /**
     * Update portfolio profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can update portfolios'
            ], 403);
        }

        // Students can update portfolios

        // Log all incoming data for debugging
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
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120', // 5MB
            'project_links' => 'nullable|string',
        ]);

        // Custom validation for project_links
        if ($request->has('project_links') && !empty($request->input('project_links'))) {
            $projectLinks = $request->input('project_links');
            
            // Try to decode JSON
            if (is_string($projectLinks)) {
                $decoded = json_decode($projectLinks, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => ['project_links' => ['Formato JSON inválido para project_links']]
                    ], 422);
                }
            }
            
            if (is_array($projectLinks)) {
                foreach ($projectLinks as $index => $link) {
                    if (is_array($link)) {
                        // New object structure
                        $url = trim($link['url'] ?? '');
                        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Validation failed',
                                'errors' => ['project_links' => ["Link no índice {$index} é inválido: {$url}"]]
                            ], 422);
                        }
                    } else {
                        // Legacy string structure
                        $url = trim($link);
                        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Validation failed',
                                'errors' => ['project_links' => ["Link no índice {$index} é inválido: {$url}"]]
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
                'input_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // CRITICAL DEBUG: Check user authentication and portfolio ownership
            Log::info('CRITICAL DEBUG - User and Portfolio Check', [
                'authenticated_user_id' => $user->id,
                'authenticated_user_email' => $user->email,
                'authenticated_user_name' => $user->name,
                'user_role' => $user->role,
            ]);
            
            // Check if user already has a portfolio
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
            
            // CRITICAL DEBUG: Verify portfolio ownership after creation/retrieval
            Log::info('CRITICAL DEBUG - Portfolio After firstOrCreate', [
                'portfolio_id' => $portfolio->id,
                'portfolio_user_id' => $portfolio->user_id,
                'authenticated_user_id' => $user->id,
                'ownership_match' => $portfolio->user_id === $user->id,
                'current_title' => $portfolio->title,
                'current_bio' => $portfolio->bio,
            ]);
            
            // Get raw input data
            $rawTitle = $request->input('title');
            $rawBio = $request->input('bio');

            Log::info('Raw input data', [
                'user_id' => $user->id,
                'raw_title' => $rawTitle,
                'raw_bio' => $rawBio,
                'title_type' => gettype($rawTitle),
                'bio_type' => gettype($rawBio),
            ]);

            // Use the raw input data directly instead of request->only()
            $data = [];

            // Always update fields that are present in the request, even if empty
            // This allows users to clear their title/bio by sending empty strings
            if ($request->has('title')) {
                $data['title'] = $rawTitle ?: null; // Convert empty string to null
            }
            if ($request->has('bio')) {
                $data['bio'] = $rawBio ?: null; // Convert empty string to null
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
                'all_request_data' => $request->all()
            ]);

            // Handle project_links (preserve se ausente ou string vazia)
            if ($request->has('project_links')) {
                $projectLinksRaw = $request->input('project_links');

                // Se vier string vazia, não atualiza (preserva valor atual)
                if (is_string($projectLinksRaw) && trim($projectLinksRaw) === '') {
                    // skip
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
                                'errors' => ['project_links' => ['Formato JSON inválido para project_links']]
                            ], 422);
                        }
                    }

                    if (is_array($projectLinks)) {
                        $validLinks = [];
                        foreach ($projectLinks as $link) {
                            if (is_array($link)) {
                                // New object structure
                                if (!empty(trim($link['title'] ?? '')) && !empty(trim($link['url'] ?? ''))) {
                                    $validLinks[] = [
                                        'title' => trim($link['title']),
                                        'url' => trim($link['url'])
                                    ];
                                }
                            } else {
                                // Legacy string structure - convert to object
                                if (!empty(trim($link))) {
                                    $validLinks[] = [
                                        'title' => 'Link',
                                        'url' => trim($link)
                                    ];
                                }
                            }
                        }
                        // Só atualiza se houver algo válido; se vier array vazio, interpreta como limpar explicitamente
                        $data['project_links'] = count($validLinks) > 0 ? $validLinks : [];
                    }
                }
            } else {
                // Se campo não veio, preserva valor existente
            }

            // Handle profile picture upload
            if ($request->hasFile('profile_picture')) {
                // Delete old profile picture
                if ($portfolio->profile_picture) {
                    Storage::disk('public')->delete($portfolio->profile_picture);
                }

                $file = $request->file('profile_picture');
                $filePath = $file->store('portfolio/' . $user->id, 'public');
                
                $data['profile_picture'] = $filePath;
            }

            Log::info('About to update portfolio with data', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'data_to_update' => $data,
                'current_title' => $portfolio->title,
                'current_bio' => $portfolio->bio
            ]);
            
            // CRITICAL SAFETY CHECK: Ensure portfolio belongs to authenticated user
            if ($portfolio->user_id !== $user->id) {
                Log::error('CRITICAL SECURITY ISSUE - Portfolio ownership mismatch', [
                    'portfolio_user_id' => $portfolio->user_id,
                    'authenticated_user_id' => $user->id,
                    'portfolio_id' => $portfolio->id,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Security error: Portfolio ownership mismatch'
                ], 403);
            }
            
            // Only update if there's data to update
            if (!empty($data)) {
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
                    'raw_bio' => $rawBio
                ]);
                $updateResult = true; // No update needed
            }
            
            Log::info('Portfolio update result', [
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'update_result' => $updateResult
            ]);
            
            // Refresh the portfolio to get updated data
            $portfolio->refresh();
            
            // CRITICAL VERIFICATION: Check if data was actually saved
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
            
            // If data doesn't match, this is a critical bug
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
                'saved_project_links' => $portfolio->project_links
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Portfolio updated successfully',
                'data' => [
                    'id' => $portfolio->id,
                    'user_id' => $portfolio->user_id,
                    'title' => $portfolio->title,
                    'bio' => $portfolio->bio,
                    'profile_picture' => $portfolio->profile_picture ? asset('storage/' . $portfolio->profile_picture) : null,
                    'profile_picture_url' => $portfolio->profile_picture ? asset('storage/' . $portfolio->profile_picture) : null,
                    'project_links' => $portfolio->project_links ?? [],
                    'created_at' => $portfolio->created_at,
                    'updated_at' => $portfolio->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update portfolio profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update portfolio profile'
            ], 500);
        }
    }

    /**
     * Test endpoint for debugging portfolio updates
     */
    public function testUpdate(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Get all possible ways to access the data
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
            ]
        ]);
    }

    /**
     * Test endpoint for debugging file uploads
     */
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
            ]
        ]);
    }

    /**
     * Upload portfolio media files
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas criadores podem fazer upload de mídia'
            ], 403);
        }
        

        try {
            // Get uploaded files - handle both 'files' and 'files[]' notation
            $uploadedFiles = $request->file('files');
            
            // If files is null or empty, check allFiles() for 'files' key
            if (empty($uploadedFiles)) {
                $allFiles = $request->allFiles();
                $uploadedFiles = $allFiles['files'] ?? [];
            }
            
            // Ensure it's always an array
            if ($uploadedFiles && !is_array($uploadedFiles)) {
                $uploadedFiles = [$uploadedFiles];
            }
            
            // If still empty, check if files were sent with different notation
            if (empty($uploadedFiles)) {
                // Try checking for any files in the request
                $allFiles = $request->allFiles();
                Log::warning('No files found with standard methods', [
                    'user_id' => $user->id,
                    'all_files_keys' => array_keys($allFiles),
                    'has_files' => $request->hasFile('files'),
                    'content_type' => $request->header('Content-Type'),
                ]);
                
                if (!empty($allFiles)) {
                    // If there are files but not under 'files', log them
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
                    return $file ? [
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime' => $file->getMimeType(),
                        'is_valid' => $file->isValid()
                    ] : null;
                }, $uploadedFiles) : []
            ]);

            // Basic validation
            if (empty($uploadedFiles) || (is_array($uploadedFiles) && count($uploadedFiles) === 0)) {
                Log::warning('No files uploaded', [
                    'user_id' => $user->id,
                    'all_files' => $request->allFiles(),
                    'content_type' => $request->header('Content-Type')
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum arquivo foi enviado',
                    'errors' => ['files' => ['Nenhum arquivo foi enviado']]
                ], 422);
            }

            if (count($uploadedFiles) > 5) {
                Log::warning('Too many files uploaded', ['user_id' => $user->id, 'count' => count($uploadedFiles)]);
                return response()->json([
                    'success' => false,
                    'message' => 'Máximo de 5 arquivos por upload',
                    'errors' => ['files' => ['Máximo de 5 arquivos por upload']]
                ], 422);
            }

            $portfolio = $user->portfolio()->firstOrCreate();
            $uploadedItems = [];

            foreach ($uploadedFiles as $index => $file) {
                Log::info('Validating file', [
                    'user_id' => $user->id,
                    'index' => $index,
                    'file_exists' => !is_null($file),
                    'is_valid' => $file ? $file->isValid() : false,
                    'mime_type' => $file ? $file->getMimeType() : null,
                    'file_size' => $file ? $file->getSize() : null,
                    'max_size' => self::MAX_FILE_SIZE
                ]);

                if (!$file || !$file->isValid()) {
                    Log::error('File validation failed: invalid file', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'file' => $file
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Arquivo inválido',
                        'errors' => ['files.' . $index => ['Arquivo inválido']]
                    ], 422);
                }

                // Check file type
                $mimeType = $file->getMimeType();
                if (!in_array($mimeType, self::ACCEPTED_TYPES)) {
                    Log::error('File validation failed: unsupported MIME type', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'mime_type' => $mimeType,
                        'accepted_types' => self::ACCEPTED_TYPES
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Tipo de arquivo não suportado',
                        'errors' => ['files.' . $index => ['Tipo de arquivo não suportado: ' . $mimeType]]
                    ], 422);
                }

                // Check file size
                if ($file->getSize() > self::MAX_FILE_SIZE) {
                    Log::error('File validation failed: file too large', [
                        'user_id' => $user->id,
                        'index' => $index,
                        'file_size' => $file->getSize(),
                        'max_size' => self::MAX_FILE_SIZE
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Arquivo muito grande',
                        'errors' => ['files.' . $index => ['Arquivo muito grande. Máximo: ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB']]
                    ], 422);
                }

                // Determine media type
                if (str_starts_with($mimeType, 'image/')) {
                    $mediaType = 'image';
                } elseif (str_starts_with($mimeType, 'video/')) {
                    $mediaType = 'video';
                } else {
                    // For files with misdetected MIME types, try to determine from file extension
                    $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
                    $videoExtensions = ['mp4', 'mov', 'avi', 'mpeg', 'mpg', 'wmv', 'webm', 'ogg', 'mkv', 'flv', '3gp'];
                    $mediaType = in_array($extension, $videoExtensions) ? 'video' : 'image';
                }
                
                // Generate unique filename
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                
                // Store file
                $storedPath = $file->storeAs('portfolio/' . $user->id, $filename, 'public');
                
                // Create portfolio item
                $item = $portfolio->items()->create([
                    'file_path' => $storedPath,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $mimeType,
                    'media_type' => $mediaType,
                    'file_size' => $file->getSize(),
                    'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'order' => $portfolio->items()->count()
                ]);

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
                    'total_items' => $portfolio->items()->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to upload portfolio media', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update portfolio item
     */
    public function updateItem(Request $request, PortfolioItem $item): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can update portfolio items'
            ], 403);
        }

        // Students can't update portfolio items, return success with no changes
        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'message' => 'Students cannot update portfolio items'
            ]);
        }

        // Check if user owns this portfolio item
        if ($item->portfolio->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $item->update($request->only(['title']));

            return response()->json([
                'success' => true,
                'message' => 'Portfolio item updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update portfolio item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update portfolio item'
            ], 500);
        }
    }

    /**
     * Delete portfolio item
     */
    public function deleteItem(PortfolioItem $item): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can delete portfolio items'
            ], 403);
        }

        // Students can't delete portfolio items, return success with no changes
        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'message' => 'Students cannot delete portfolio items'
            ]);
        }

        // Check if user owns this portfolio item
        if ($item->portfolio->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            // Delete file from storage
            if ($item->file_path) {
                Storage::disk('public')->delete($item->file_path);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Portfolio item deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete portfolio item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete portfolio item'
            ], 500);
        }
    }

    /**
     * Reorder portfolio items
     */
    public function reorderItems(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can reorder portfolio items'
            ], 403);
        }

        // Students can't reorder portfolio items, return success with no changes
        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'message' => 'Students cannot reorder portfolio items'
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
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $portfolio = $user->portfolio;
            if (!$portfolio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Portfolio not found'
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
                'message' => 'Portfolio items reordered successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reorder portfolio items: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder portfolio items'
            ], 500);
        }
    }

    /**
     * Get portfolio statistics
     */
    public function statistics(): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can view portfolio statistics'
            ], 403);
        }

        // Students don't have portfolio statistics, return empty data
        if ($user->isStudent()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_items' => 0,
                    'image_count' => 0,
                    'video_count' => 0,
                    'last_updated' => null
                ]
            ]);
        }

        $portfolio = $user->portfolio()->with('items')->first();

        if (!$portfolio) {
            return response()->json([
                'success' => false,
                'message' => 'Portfolio not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_items' => $portfolio->items->count(),
                'image_count' => $portfolio->items->where('media_type', 'image')->count(),
                'video_count' => $portfolio->items->where('media_type', 'video')->count(),
                'last_updated' => $portfolio->updated_at
            ]
        ]);
    }

    /**
     * Get creator profile for brands (public view)
     */
    public function getCreatorProfile($creatorId): JsonResponse
    {
        try {
            // Check if user is authenticated
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Find the creator or student
            $creator = User::find($creatorId);
            if (!$creator || (!$creator->isCreator() && !$creator->isStudent())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Creator not found'
                ], 404);
            }

            $portfolio = $creator->portfolio()->with(['items' => function ($query) {
                $query->orderBy('order');
            }])->first();

            if (!$portfolio) {
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
                        'reviews' => []
                    ]
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
                        'profile_picture' => $portfolio->profile_picture ? asset('storage/' . $portfolio->profile_picture) : null,
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
                    'reviews' => []
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get creator profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get creator profile'
            ], 500);
        }
    }
}
