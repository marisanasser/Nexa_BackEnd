<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{


    /**
     * Get the current user's profile
     */
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'profile' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'whatsapp' => $user->whatsapp,
                    'avatar' => $user->avatar_url,
                    'bio' => $user->bio,
                    'company_name' => $user->company_name,
                    'profession' => $user->profession,
                    'gender' => $user->gender,
                    'birth_date' => $user->birth_date,
                    'creator_type' => $user->creator_type,
                    'instagram_handle' => $user->instagram_handle,
                    'tiktok_handle' => $user->tiktok_handle,
                    'youtube_channel' => $user->youtube_channel,
                    'facebook_page' => $user->facebook_page,
                    'twitter_handle' => $user->twitter_handle,
                    'industry' => $user->industry,
                    'niche' => $user->niche,
                    'state' => $user->state, // Return state directly instead of mapping to location
                    'language' => $user->language,
                    'languages' => $user->languages ?: ($user->language ? [$user->language] : []),
                    'categories' => ['General'], // Default categories
                    'has_premium' => $user->has_premium,
                    'student_verified' => $user->student_verified,
                    'premium_expires_at' => $user->premium_expires_at,
                    'free_trial_expires_at' => $user->free_trial_expires_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
                'message' => 'Profile retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the current user's profile
     */
    public function update(Request $request): JsonResponse
    {
        // Enhanced debug logging for FormData
        error_log("Content-Type: " . $request->header('Content-Type'));
        error_log("Request method: " . $request->method());
        error_log("Request role: " . $request->input('role'));
        error_log("Request state: " . $request->input('state'));
        error_log("Request all data: " . json_encode($request->all()));
        error_log("Request files: " . json_encode($request->allFiles()));
        error_log("Raw content length: " . strlen($request->getContent()));
        
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Check if this is a multipart form data request
            $contentType = $request->header('Content-Type');
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
            
            error_log("Is multipart: " . ($isMultipart ? 'true' : 'false'));

            // If it's multipart but no data is parsed, try manual parsing
            if ($isMultipart && empty($request->all()) && !empty($request->getContent())) {
                error_log("Attempting manual multipart parsing");
                $parsedData = $this->parseMultipartData($request);
                error_log("Manually parsed data: " . json_encode($parsedData));
                
                // Merge manually parsed data with request
                foreach ($parsedData as $key => $value) {
                    if ($key !== 'avatar') {
                        $request->merge([$key => $value]);
                    }
                }
            }

            // Build validation rules dynamically
            $validationRules = [
                'name' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id),
                ],
                'role' => 'sometimes|string|max:255',
                'whatsapp' => 'sometimes|string|max:20',
                'bio' => 'sometimes|string|max:1000',
                'company_name' => 'sometimes|string|max:255',
                'gender' => 'sometimes|string|max:50',
                'birth_date' => 'sometimes|date',
                'creator_type' => 'sometimes|string|in:ugc,influencer,both',
                'tiktok_handle' => 'sometimes|string|max:255',
                'youtube_channel' => 'sometimes|string|max:255',
                'facebook_page' => 'sometimes|string|max:255',
                'twitter_handle' => 'sometimes|string|max:255',
                'industry' => 'sometimes|string|max:255',
                'profession' => 'sometimes|string|max:255',
                'niche' => 'sometimes|string|max:255',
                'state' => 'sometimes|string|max:255', // Accept state directly instead of location
                'language' => 'sometimes|string|max:50',
                'languages' => 'sometimes|string', // JSON string for multiple languages
                'categories' => 'sometimes|string', // JSON string for categories
                'avatar' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            ];

            // Handle Instagram validation based on creator type
            $creatorType = $request->input('creator_type') ?? $user->creator_type ?? null;
            
            if ($creatorType === 'influencer' || $creatorType === 'both') {
                $validationRules['instagram_handle'] = 'required|string|max:255';
            } else {
                $validationRules['instagram_handle'] = 'sometimes|string|max:255';
            }

            $validator = Validator::make($request->all(), $validationRules);

            // Handle avatar upload
            $hasAvatarFile = false;
            $avatarFile = null;
            
            if ($request->hasFile('avatar')) {
                $hasAvatarFile = true;
                $avatarFile = $request->file('avatar');
                error_log("Avatar file found via hasFile()");
            } else {
                // Try manual multipart parsing for avatar
                if ($isMultipart && !empty($request->getContent())) {
                    $parsedData = $this->parseMultipartData($request);
                    if (isset($parsedData['avatar']) && $parsedData['avatar'] instanceof \Illuminate\Http\UploadedFile) {
                        $hasAvatarFile = true;
                        $avatarFile = $parsedData['avatar'];
                        error_log("Avatar file found via manual parsing");
                    }
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Handle avatar upload
            if ($hasAvatarFile && $avatarFile) {
                // Delete old avatar if exists
                try {
                    if ($user->avatar_url) {
                        $oldPath = str_replace('/storage/', '', $user->avatar_url);
                        if (Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore delete errors in local env
                }

                // Store new avatar
                $avatarPath = $avatarFile->store('avatars', 'public');
                $data['avatar_url'] = '/storage/' . $avatarPath;
            }

            // Handle languages and categories
            if (isset($data['languages'])) {
                $languages = json_decode($data['languages'], true);
                if (is_array($languages) && !empty($languages)) {
                    $data['languages'] = $languages;
                    $data['language'] = $languages[0]; // Set first language as primary
                } else {
                    $data['languages'] = [];
                    $data['language'] = null;
                }
            }

            // Map gender values from frontend to backend
            if (isset($data['gender'])) {
                $genderMapping = [
                    'Female' => 'female',
                    'Male' => 'male',
                    'Non-binary' => 'other',
                    'Prefer not to say' => 'other'
                ];
                $data['gender'] = $genderMapping[$data['gender']] ?? $data['gender'];
            }

            // Validate role field - use input() for FormData
            $role = $request->input('role');
            if ($role) {
                $validRoles = ['creator', 'brand', 'admin', 'student'];
                if (in_array($role, $validRoles)) {
                    $data['role'] = $role;
                } else {
                    // Em vez de 422, ignorar role inválido e seguir (tolerante a UIs com "Profissão")
                }
            }

            // Handle state field - use input() for FormData
            $state = $request->input('state');
            if ($state) {
                $data['state'] = $state;
            }
            
            // Remove fields that shouldn't be updated directly  
            unset($data['categories']); // Keep languages for updating

            // Update user
            $user->update($data);

            // Refresh user data from database
            $user->refresh();

            return response()->json([
                'success' => true,
                'profile' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'whatsapp' => $user->whatsapp,
                    'avatar' => $user->avatar_url,
                    'bio' => $user->bio,
                    'company_name' => $user->company_name,
                    'profession' => $user->profession,
                    'gender' => $user->gender,
                    'birth_date' => $user->birth_date,
                    'creator_type' => $user->creator_type,
                    'instagram_handle' => $user->instagram_handle,
                    'tiktok_handle' => $user->tiktok_handle,
                    'youtube_channel' => $user->youtube_channel,
                    'facebook_page' => $user->facebook_page,
                    'twitter_handle' => $user->twitter_handle,
                    'industry' => $user->industry,
                    'niche' => $user->niche,
                    'location' => $user->state,
                    'language' => $user->language,
                    'languages' => $user->languages ?: ($user->language ? [$user->language] : []),
                    'categories' => ['General'],
                    'has_premium' => $user->has_premium,
                    'student_verified' => $user->student_verified,
                    'premium_expires_at' => $user->premium_expires_at,
                    'free_trial_expires_at' => $user->free_trial_expires_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
                'message' => 'Profile updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete current user's avatar
     */
    public function deleteAvatar(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            if ($user->avatar_url) {
                $path = str_replace('/storage/', '', $user->avatar_url);
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
                $user->avatar_url = null;
                $user->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Avatar removido com sucesso',
                'profile' => [
                    'id' => $user->id,
                    'avatar' => $user->avatar_url,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao remover avatar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload avatar only (multipart FormData with 'avatar')
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            Log::info('Avatar upload request received', [
                'content_type' => $request->header('Content-Type'),
                'method' => $request->method(),
                'has_file' => $request->hasFile('avatar'),
                'files' => $request->allFiles(),
            ]);

            $validator = Validator::make($request->all(), [
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Delete old avatar if exists
            try {
                if ($user->avatar_url) {
                    $oldPath = str_replace('/storage/', '', $user->avatar_url);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
            } catch (\Throwable $e) {}

            $avatarFile = $request->file('avatar');
            $avatarPath = $avatarFile->store('avatars', 'public');
            $user->avatar_url = '/storage/' . $avatarPath;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Avatar atualizado com sucesso',
                'profile' => [
                    'id' => $user->id,
                    'avatar' => $user->avatar_url,
                    'avatar_url' => $user->avatar_url,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar avatar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload avatar via base64 (JSON) - fallback para ambientes com issues de multipart
     * Body esperado: { "avatar_base64": "data:image/jpeg;base64,..." }
     */
    public function uploadAvatarBase64(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $dataUrl = $request->input('avatar_base64');
            if (!$dataUrl || !is_string($dataUrl)) {
                return response()->json([
                    'success' => false,
                    'message' => 'avatar_base64 é obrigatório'
                ], 422);
            }

            if (!preg_match('/^data:(image\\/(jpeg|jpg|png|webp));base64,(.*)$/i', $dataUrl, $matches)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato base64 inválido'
                ], 422);
            }

            $mime = $matches[1];
            $ext = $matches[2] === 'jpeg' ? 'jpg' : $matches[2];
            $base64 = $matches[3];
            $binary = base64_decode($base64, true);
            if ($binary === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao decodificar base64'
                ], 422);
            }

            // Tamanho máximo ~10MB
            if (strlen($binary) > 10 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo muito grande (limite 10MB)'
                ], 422);
            }

            // Remove anterior
            try {
                if ($user->avatar_url) {
                    $oldPath = str_replace('/storage/', '', $user->avatar_url);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
            } catch (\Throwable $e) {}

            // Salva novo
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $ext;
            $path = 'avatars/' . $filename;
            Storage::disk('public')->put($path, $binary);

            $user->avatar_url = '/storage/' . $path;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Avatar atualizado com sucesso (base64)',
                'profile' => [
                    'id' => $user->id,
                    'avatar' => $user->avatar_url,
                    'avatar_url' => $user->avatar_url,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar avatar (base64): ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse multipart form data manually
     */
    private function parseMultipartData(Request $request): array
    {
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');
        
        // Extract boundary
        if (!preg_match('/boundary=(.+)$/', $contentType, $matches)) {
            return [];
        }
        
        $boundary = '--' . trim($matches[1]);
        $parts = explode($boundary, $rawContent);
        $parsedData = [];
        
        foreach ($parts as $part) {
            if (empty(trim($part)) || $part === '--') {
                continue;
            }
            
            // Parse headers
            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }
            
            $headers = substr($part, 0, $headerEnd);
            $content = substr($part, $headerEnd + 4);
            $content = rtrim($content, "\r\n-");
            
            // Extract field name
            if (preg_match('/name="([^"]+)"/', $headers, $matches)) {
                $fieldName = $matches[1];
                
                // Check if it's a file
                if (preg_match('/filename="([^"]+)"/', $headers, $matches)) {
                    $filename = $matches[1];
                    
                    if (!empty($content)) {
                        // Create temporary file
                        $tempPath = tempnam(sys_get_temp_dir(), 'upload_');
                        file_put_contents($tempPath, $content);
                        
                        // Create UploadedFile object
                        $parsedData[$fieldName] = new \Illuminate\Http\UploadedFile(
                            $tempPath,
                            $filename,
                            mime_content_type($tempPath),
                            null,
                            true
                        );
                    }
                } else {
                    // Regular field
                    $parsedData[$fieldName] = $content;
                }
            }
        }
        
        return $parsedData;
    }
} 