<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Helpers\FileUploadHelper;
use App\Http\Controllers\Base\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ProfileController extends Controller
{
    use \App\Domain\Shared\Traits\HasAuthenticatedUser;

    public function show(): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $user->load('portfolio');

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'profile' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => FileUploadHelper::resolveUrl($user->avatar_url),
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
                    'niches' => $this->normalizeNiches($user->niches, $user->niche),
                    'niche' => $this->primaryNiche($user->niches, $user->niche),
                    'state' => $user->state,
                    'language' => $user->language,
                    'languages' => $user->languages ?: ($user->language ? [$user->language] : []),
                    'categories' => ['General'],
                    'has_premium' => $user->has_premium,
                    'student_verified' => $user->student_verified,
                    'premium_expires_at' => $user->premium_expires_at,
                    'free_trial_expires_at' => $user->free_trial_expires_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'portfolio' => $user->portfolio,
                ],
                'message' => 'Profile retrieved successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        error_log('Content-Type: ' . $request->header('Content-Type'));
        error_log('Request method: ' . $request->method());
        error_log('Request role: ' . $request->input('role'));
        error_log('Request state: ' . $request->input('state'));
        error_log('Request all data: ' . json_encode($request->all()));
        error_log('Request files: ' . json_encode($request->allFiles()));
        error_log('Raw content length: ' . strlen($request->getContent()));

        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $contentType = $request->header('Content-Type');
            $isMultipart = str_contains($contentType, 'multipart/form-data');

            error_log('Is multipart: ' . ($isMultipart ? 'true' : 'false'));

            if ($isMultipart && empty($request->all()) && !empty($request->getContent())) {
                error_log('Attempting manual multipart parsing');
                $parsedData = $this->parseMultipartData($request);
                error_log('Manually parsed data: ' . json_encode($parsedData));

                foreach ($parsedData as $key => $value) {
                    if ('avatar' !== $key) {
                        $request->merge([$key => $value]);
                    }
                }
            }

            $hasNichePayload = $request->exists('niches') || $request->exists('niche');
            if ($hasNichePayload) {
                $normalizedNiches = $this->normalizeNiches(
                    $request->input('niches'),
                    is_string($request->input('niche')) ? $request->input('niche') : null
                );

                $request->merge([
                    'niches' => $normalizedNiches,
                    'niche' => $normalizedNiches[0] ?? null,
                ]);
            }

            // Handle project_links JSON string from FormData
            if ($request->has('project_links')) {
                $links = $request->input('project_links');
                error_log('Received project_links type: ' . gettype($links));

                if (is_string($links)) {
                    error_log('Received project_links string: ' . substr($links, 0, 100) . '...');
                    $decodedLinks = json_decode($links, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        error_log('JSON decode error: ' . json_last_error_msg());
                        // Try stripslashes (sometimes needed for double-encoded JSON)
                        $decodedLinks = json_decode(stripslashes($links), true);
                    }

                    if (is_array($decodedLinks)) {
                        error_log('Successfully decoded project_links to array count: ' . count($decodedLinks));
                        $request->merge(['project_links' => $decodedLinks]);
                    } else {
                        error_log('Failed to decode project_links, resetting to empty array');
                        $request->merge(['project_links' => []]);
                    }
                } elseif (is_array($links)) {
                    error_log('Received project_links as array count: ' . count($links));
                }
            } else {
                // Ensure it's not present if not sent, or handle as empty if required
            }

            // Ensure project_links is an array for validation
            if ($request->has('project_links') && !is_array($request->input('project_links'))) {
                $request->merge(['project_links' => []]);
            }

            // DEBUG: Log project_links in request right before validation
            error_log('Request project_links before validation: ' . json_encode($request->input('project_links')));

            $validationRules = [
                'name' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id),
                ],
                'role' => 'sometimes|string|max:255',
                'bio' => 'sometimes|nullable|string|max:1000',
                'company_name' => 'sometimes|nullable|string|max:255',
                'gender' => 'sometimes|string|max:50',
                'birth_date' => 'sometimes|date',
                'creator_type' => 'sometimes|string|in:ugc,influencer,both',
                'tiktok_handle' => 'sometimes|nullable|string|max:255',
                'youtube_channel' => 'sometimes|nullable|string|max:255',
                'facebook_page' => 'sometimes|nullable|string|max:255',
                'twitter_handle' => 'sometimes|nullable|string|max:255',
                'industry' => 'sometimes|nullable|string|max:255',
                'profession' => 'sometimes|nullable|string|max:255',
                'niche' => 'sometimes|nullable|string|max:255',
                'niches' => 'sometimes|array',
                'niches.*' => 'sometimes|string|max:255',
                'state' => 'sometimes|nullable|string|max:255',
                'language' => 'sometimes|nullable|string|max:50',
                'languages' => 'sometimes',
                'categories' => 'sometimes|nullable|string',
                'avatar' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
                'project_links' => 'sometimes|array',
                'project_links.*.url' => 'required_with:project_links|url',
                'project_links.*.title' => 'nullable|string|max:255',
            ];

            $creatorTypeFromRequest = $request->input('creator_type');

            if ('influencer' === $creatorTypeFromRequest || 'both' === $creatorTypeFromRequest) {
                $validationRules['instagram_handle'] = 'required|string|max:255';
            } else {
                $validationRules['instagram_handle'] = 'sometimes|nullable|string|max:255';
            }

            $validator = Validator::make($request->all(), $validationRules);

            $hasAvatarFile = false;
            $avatarFile = null;

            if ($request->hasFile('avatar')) {
                $hasAvatarFile = true;
                $avatarFile = $request->file('avatar');
                error_log('Avatar file found via hasFile()');
            } else {
                if ($isMultipart && !empty($request->getContent())) {
                    $parsedData = $this->parseMultipartData($request);
                    if (isset($parsedData['avatar']) && $parsedData['avatar'] instanceof UploadedFile) {
                        $hasAvatarFile = true;
                        $avatarFile = $parsedData['avatar'];
                        error_log('Avatar file found via manual parsing');
                    }
                }
            }

            if ($validator->fails()) {
                Log::error('Profile update validation failed', [
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

            $data = $validator->validated();

            if (array_key_exists('niches', $data) || array_key_exists('niche', $data)) {
                $normalizedNiches = $this->normalizeNiches(
                    $data['niches'] ?? null,
                    isset($data['niche']) && is_string($data['niche']) ? $data['niche'] : null
                );
                $data['niches'] = $normalizedNiches;
                $data['niche'] = $normalizedNiches[0] ?? null;
            }

            if ($hasAvatarFile && $avatarFile) {
                // Delete old avatar
                if ($user->avatar_url) {
                    FileUploadHelper::delete($user->avatar_url);
                }

                $avatarUrl = FileUploadHelper::upload($avatarFile, 'avatars');
                if ($avatarUrl) {
                    $data['avatar_url'] = $avatarUrl;

                    // Sync with Portfolio
                    try {
                        if ($user->portfolio) {
                            $user->portfolio->profile_picture = $avatarUrl;
                            $user->portfolio->save();
                        }
                    } catch (Throwable $e) {
                        // Ignore sync errors
                    }
                }
            }

            if (array_key_exists('languages', $data)) {
                $languagesRaw = $data['languages'];
                $languages = null;

                if (is_string($languagesRaw)) {
                    $decoded = json_decode($languagesRaw, true);
                    if (JSON_ERROR_NONE === json_last_error()) {
                        $languages = $decoded;
                    }
                } elseif (is_array($languagesRaw)) {
                    $languages = $languagesRaw;
                }

                if (is_array($languages) && !empty($languages)) {
                    $data['languages'] = $languages;
                    $data['language'] = $languages[0];
                } else {
                    $data['languages'] = [];
                    $data['language'] = null;
                }
            }

            if (isset($data['gender'])) {
                $genderMapping = [
                    'Female' => 'female',
                    'Male' => 'male',
                    'Non-binary' => 'other',
                    'Prefer not to say' => 'other',
                ];
                $data['gender'] = $genderMapping[$data['gender']] ?? $data['gender'];
            }

            if (array_key_exists('role', $data)) {
                unset($data['role']);
            }

            $state = $request->input('state');
            if ($state) {
                $data['state'] = $state;
            }

            unset($data['categories']);

            // project_links belongs to portfolio, not user table
            $projectLinks = null;
            if (array_key_exists('project_links', $data)) {
                $projectLinks = $data['project_links'];
                unset($data['project_links']);
            }

            $user->update($data);

            // Sync with Portfolio (Bidirectional sync for Bio)
            try {
                $portfolio = $user->portfolio;
                if (!$portfolio) {
                    $portfolio = $user->portfolio()->create([
                        'user_id' => $user->id,
                        'title' => $user->name . "'s Portfolio",
                    ]);
                }

                if ($portfolio) {
                    $portfolioData = [];
                    // Sync Bio if present
                    if (array_key_exists('bio', $data)) {
                        $portfolioData['bio'] = $data['bio'];
                    }

                    // Sync Project Links if present
                    if ($projectLinks !== null) {
                        $portfolioData['project_links'] = $projectLinks;
                    }

                    if (!empty($portfolioData)) {
                        $portfolio->update($portfolioData);
                        Log::info('Synced User profile changes to Portfolio', [
                            'user_id' => $user->id,
                            'fields' => array_keys($portfolioData)
                        ]);
                    }
                }
            } catch (Throwable $e) {
                // Ignore sync errors
                Log::warning('Failed to sync profile changes to portfolio', ['error' => $e->getMessage()]);
            }

            $user->refresh();
            $user->load('portfolio');

            $avatarUrl = FileUploadHelper::resolveUrl($user->avatar_url);
            // Ensure absolute URL
            if ($avatarUrl && !str_starts_with($avatarUrl, 'http')) {
                if (!str_contains($avatarUrl, 'storage/')) {
                    $avatarUrl = asset('storage/' . ltrim($avatarUrl, '/'));
                } else {
                    $avatarUrl = asset($avatarUrl);
                }
            }

            return response()->json([
                'success' => true,
                'profile' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl,
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
                    'niches' => $this->normalizeNiches($user->niches, $user->niche),
                    'niche' => $this->primaryNiche($user->niches, $user->niche),
                    'location' => $user->state,
                    'state' => $user->state,
                    'language' => $user->language,
                    'languages' => $user->languages ?: ($user->language ? [$user->language] : []),
                    'categories' => ['General'],
                    'has_premium' => $user->has_premium,
                    'student_verified' => $user->student_verified,
                    'premium_expires_at' => $user->premium_expires_at,
                    'free_trial_expires_at' => $user->free_trial_expires_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'portfolio' => $user->portfolio,
                ],
                'message' => 'Profile updated successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function normalizeNiches(mixed $niches, ?string $legacyNiche = null): array
    {
        $values = [];

        if (is_array($niches)) {
            $values = $niches;
        } elseif (is_string($niches)) {
            $decoded = json_decode($niches, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $values = $decoded;
            } elseif (trim($niches) !== '') {
                $values = [$niches];
            }
        }

        if (empty($values) && is_string($legacyNiche) && trim($legacyNiche) !== '') {
            $values = [$legacyNiche];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $item = trim($value);
            if ($item === '' || in_array($item, $normalized, true)) {
                continue;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function primaryNiche(mixed $niches, ?string $legacyNiche = null): ?string
    {
        $normalized = $this->normalizeNiches($niches, $legacyNiche);

        return $normalized[0] ?? null;
    }

    public function deleteAvatar(): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if ($user->avatar_url) {
                FileUploadHelper::delete($user->avatar_url);
                $user->avatar_url = null;
                $user->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Avatar removido com sucesso',
                'profile' => [
                    'id' => $user->id,
                    'avatar' => $user->avatar_url,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao remover avatar: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
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
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Delete old avatar
            if ($user->avatar_url) {
                FileUploadHelper::delete($user->avatar_url);
            }

            $avatarUrl = FileUploadHelper::upload($request->file('avatar'), 'avatars');
            $user->avatar_url = $avatarUrl;
            $user->save();

            $resolvedAvatarUrl = FileUploadHelper::resolveUrl($user->avatar_url);
            // Ensure absolute URL
            if ($resolvedAvatarUrl && !str_starts_with($resolvedAvatarUrl, 'http')) {
                if (!str_contains($resolvedAvatarUrl, 'storage/')) {
                    $resolvedAvatarUrl = asset('storage/' . ltrim($resolvedAvatarUrl, '/'));
                } else {
                    $resolvedAvatarUrl = asset($resolvedAvatarUrl);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Avatar atualizado com sucesso',
                'avatar_url' => $resolvedAvatarUrl,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar avatar: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function uploadAvatarBase64(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $dataUrl = $request->input('avatar_base64');
            if (!$dataUrl || !is_string($dataUrl)) {
                return response()->json([
                    'success' => false,
                    'message' => 'avatar_base64 é obrigatório',
                ], 422);
            }

            if (!preg_match('/^data:(image\/(jpeg|jpg|png|webp));base64,(.*)$/i', $dataUrl, $matches)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato base64 inválido',
                ], 422);
            }

            $mime = $matches[1];
            $ext = 'jpeg' === $matches[2] ? 'jpg' : $matches[2];
            $base64 = $matches[3];
            $binary = base64_decode($base64, true);
            if (false === $binary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao decodificar base64',
                ], 422);
            }

            if (strlen($binary) > 10 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo muito grande (limite 10MB)',
                ], 422);
            }

            // Delete old avatar
            if ($user->avatar_url) {
                FileUploadHelper::delete($user->avatar_url);
            }

            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $ext;
            $tempPath = sys_get_temp_dir() . '/' . $filename;
            file_put_contents($tempPath, $binary);

            $uploadedFile = new UploadedFile(
                $tempPath,
                $filename,
                $mime,
                null,
                true
            );

            $avatarUrl = FileUploadHelper::upload($uploadedFile, 'avatars');

            if ($avatarUrl) {
                $user->avatar_url = $avatarUrl;
                $user->save();

                // Sync with Portfolio
                try {
                    if ($user->portfolio) {
                        $user->portfolio->profile_picture = $avatarUrl;
                        $user->portfolio->save();
                    }
                } catch (Throwable $e) {
                }
            }

            // Cleanup temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Avatar atualizado com sucesso',
                'profile' => [
                    'id' => $user->id,
                    'avatar' => FileUploadHelper::resolveUrl($user->avatar_url),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar avatar: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function parseMultipartData(Request $request): array
    {
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');

        // Better boundary extraction handling optional quotes and extra params
        if (!preg_match('/boundary="?([^";]+)"?/', $contentType, $matches)) {
            return [];
        }

        $boundary = '--' . $matches[1];
        $parts = explode($boundary, $rawContent);
        $parsedData = [];

        foreach ($parts as $part) {
            if (empty(trim($part)) || '--' === $part) {
                continue;
            }

            $headerEnd = strpos($part, "\r\n\r\n");
            if (false === $headerEnd) {
                continue;
            }

            $headers = substr($part, 0, $headerEnd);
            $content = substr($part, $headerEnd + 4);
            $content = rtrim($content, "\r\n-");

            if (preg_match('/name="([^"]+)"/', $headers, $matches)) {
                $fieldName = $matches[1];

                if (preg_match('/filename="([^"]+)"/', $headers, $matches)) {
                    $filename = $matches[1];

                    if (!empty($content)) {
                        $tempPath = tempnam(sys_get_temp_dir(), 'upload_');
                        file_put_contents($tempPath, $content);

                        $parsedData[$fieldName] = new UploadedFile(
                            $tempPath,
                            $filename,
                            mime_content_type($tempPath),
                            null,
                            true
                        );
                    }
                } else {
                    $parsedData[$fieldName] = $content;
                }
            }
        }

        return $parsedData;
    }
}
