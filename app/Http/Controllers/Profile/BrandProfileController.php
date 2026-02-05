<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Helpers\FileUploadHelper;
use App\Http\Controllers\Base\Controller;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BrandProfileController extends Controller
{
    public function show(): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Brand role required.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => FileUploadHelper::resolveUrl($user->avatar_url),
                    'avatar_url' => FileUploadHelper::resolveUrl($user->avatar_url),
                    'company_name' => $user->company_name,
                    'cnpj' => $user->cnpj,
                    'website' => $user->website,
                    'description' => $user->bio,
                    'niche' => $user->niche,
                    'address' => $user->address,
                    'city' => $user->city,
                    'whatsapp_number' => $user->whatsapp_number,
                    'gender' => $user->gender,
                    'state' => $user->state,
                    'languages' => $user->languages ?? [],
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile: '.$e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Brand role required.',
                ], 403);
            }

            $contentType = $request->header('Content-Type');
            $isMultipart = str_contains($contentType, 'multipart/form-data');

            error_log('Brand Profile - Is multipart: '.($isMultipart ? 'true' : 'false'));

            if ($isMultipart && empty($request->all()) && !empty($request->getContent())) {
                error_log('Brand Profile - Attempting manual multipart parsing');
                $parsedData = $this->parseMultipartData($request);
                error_log('Brand Profile - Manually parsed data: '.json_encode($parsedData));

                foreach ($parsedData as $key => $value) {
                    if ('avatar' !== $key) {
                        $request->merge([$key => $value]);
                    }
                }
            }

            $validator = Validator::make($request->all(), [
                'username' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id),
                ],
                'company_name' => 'sometimes|string|max:255',
                'cnpj' => 'sometimes|string|max:20',
                'website' => 'sometimes|nullable|string|max:255',
                'description' => 'sometimes|nullable|string',
                'niche' => 'sometimes|nullable|string|max:255',
                'address' => 'sometimes|nullable|string|max:255',
                'city' => 'sometimes|nullable|string|max:255',
                'whatsapp_number' => 'sometimes|string|max:20',
                'gender' => 'sometimes|nullable|string|in:male,female,other',
                'state' => 'sometimes|nullable|string|max:255',
                'avatar' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $hasAvatarFile = false;
            $avatarFile = null;

            if ($request->hasFile('avatar')) {
                $hasAvatarFile = true;
                $avatarFile = $request->file('avatar');
                error_log('Brand Profile - Avatar file found via hasFile()');
            } else {
                if ($isMultipart && !empty($request->getContent())) {
                    $parsedData = $this->parseMultipartData($request);
                    if (isset($parsedData['avatar']) && $parsedData['avatar'] instanceof UploadedFile) {
                        $hasAvatarFile = true;
                        $avatarFile = $parsedData['avatar'];
                        error_log('Brand Profile - Avatar file found via manual parsing');
                    }
                }
            }

            if ($validator->fails()) {
                error_log('Brand Profile - Validation failed: '.json_encode($validator->errors()));

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            if (isset($data['username'])) {
                $data['name'] = $data['username'];
                unset($data['username']);
            }

            if (isset($data['description'])) {
                $data['bio'] = $data['description'];
                unset($data['description']);
            }

            if ($hasAvatarFile && $avatarFile) {
                // Delete old avatar
                if ($user->avatar_url) {
                    FileUploadHelper::delete($user->avatar_url);
                }

                $avatarUrl = FileUploadHelper::upload($avatarFile, 'avatars');
                if ($avatarUrl) {
                    $data['avatar_url'] = $avatarUrl;
                    error_log('Brand Profile - Avatar stored at: '.$avatarUrl);
                }
            }

            unset($data['avatar']);

            $user->update($data);

            $user->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => FileUploadHelper::resolveUrl($user->avatar_url),
                    'avatar_url' => FileUploadHelper::resolveUrl($user->avatar_url),
                    'company_name' => $user->company_name,
                    'cnpj' => $user->cnpj,
                    'website' => $user->website,
                    'description' => $user->bio,
                    'niche' => $user->niche,
                    'address' => $user->address,
                    'city' => $user->city,
                    'whatsapp_number' => $user->whatsapp_number,
                    'gender' => $user->gender,
                    'state' => $user->state,
                    'languages' => $user->languages ?? [],
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
            ]);
        } catch (Exception $e) {
            error_log('Brand Profile - Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: '.$e->getMessage(),
            ], 500);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Brand role required.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
                'new_password_confirmation' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if (!Hash::check($request->old_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect',
                ], 400);
            }

            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password: '.$e->getMessage(),
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

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Brand role required.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'avatar' => 'required',
            ]);

            $hasAvatarFile = false;
            $avatarFile = null;
            $hasAvatarBase64 = false;
            $avatarBase64 = null;

            if (!$request->hasFile('avatar')) {
                if ($request->has('avatar') && is_string($request->input('avatar'))) {
                    $avatarInput = $request->input('avatar');
                    if (str_starts_with($avatarInput, 'data:image/')) {
                        $hasAvatarBase64 = true;
                        $avatarBase64 = $avatarInput;
                    }
                }

                $rawContent = $request->getContent();
                $boundary = null;

                $contentType = $request->header('Content-Type');
                if (preg_match('/boundary=(.+)$/', $contentType, $matches)) {
                    $boundary = '--'.trim($matches[1]);
                }

                if ($boundary && str_contains($rawContent, 'Content-Disposition: form-data; name="avatar"')) {
                    $parts = explode($boundary, $rawContent);
                    foreach ($parts as $part) {
                        if (str_contains($part, 'Content-Disposition: form-data; name="avatar"')) {
                            if (preg_match('/filename="([^"]+)"/', $part, $matches)) {
                                $filename = $matches[1];

                                $fileContent = substr($part, strpos($part, "\r\n\r\n") + 4);
                                $fileContent = rtrim($fileContent, "\r\n-");

                                if (!empty($fileContent)) {
                                    $hasAvatarFile = true;

                                    $tempPath = tempnam(sys_get_temp_dir(), 'avatar_');
                                    file_put_contents($tempPath, $fileContent);

                                    $avatarFile = new UploadedFile(
                                        $tempPath,
                                        $filename,
                                        mime_content_type($tempPath),
                                        null,
                                        true
                                    );
                                }
                            }

                            break;
                        }
                    }
                }
            } else {
                $hasAvatarFile = true;
                $avatarFile = $request->file('avatar');
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if (!$hasAvatarFile && !$hasAvatarBase64) {
                return response()->json([
                    'success' => false,
                    'message' => 'No avatar file or base64 data provided',
                ], 400);
            }

            $avatarUrl = null;

            if ($hasAvatarFile && $avatarFile) {
                // Delete old avatar
                if ($user->avatar_url) {
                    FileUploadHelper::delete($user->avatar_url);
                }

                $avatarUrl = FileUploadHelper::upload($avatarFile, 'avatars');
                if ($avatarUrl) {
                    $user->update(['avatar_url' => $avatarUrl]);
                }
            } elseif ($hasAvatarBase64 && $avatarBase64) {
                $avatarResult = $this->handleAvatarUpload($avatarBase64, $user);
                if (!$avatarResult['success']) {
                    return response()->json($avatarResult, 400);
                }
                $avatarUrl = $avatarResult['avatar_url'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'avatar' => FileUploadHelper::resolveUrl($avatarUrl),
                    'updated_at' => $user->updated_at,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload avatar: '.$e->getMessage(),
            ], 500);
        }
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

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Brand role required.',
                ], 403);
            }

            if ($user->avatar_url && Storage::disk('public')->exists(str_replace('/storage/', '', $user->avatar_url))) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $user->avatar_url));
            }

            $user->update(['avatar_url' => null]);

            return response()->json([
                'success' => true,
                'message' => 'Avatar deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete avatar: '.$e->getMessage(),
            ], 500);
        }
    }

    private function parseMultipartData(Request $request): array
    {
        $rawContent = $request->getContent();
        $contentType = $request->header('Content-Type');

        if (!preg_match('/boundary=(.+)$/', $contentType, $matches)) {
            return [];
        }

        $boundary = '--'.trim($matches[1]);
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

    private function handleAvatarUpload(string $base64Data, $user): array
    {
        if (!preg_match('/^data:image\/(jpeg|png|jpg|gif|webp|svg\+xml);base64,/', $base64Data)) {
            return [
                'success' => false,
                'message' => 'Invalid image format. Please provide a valid base64 encoded image.',
            ];
        }

        try {
            $base64Image = str_replace('data:image/jpeg;base64,', '', $base64Data);
            $base64Image = str_replace('data:image/png;base64,', '', $base64Image);
            $base64Image = str_replace('data:image/jpg;base64,', '', $base64Image);
            $base64Image = str_replace('data:image/gif;base64,', '', $base64Image);
            $base64Image = str_replace('data:image/webp;base64,', '', $base64Image);
            $base64Image = str_replace('data:image/svg+xml;base64,', '', $base64Image);

            $imageData = base64_decode($base64Image);

            if (false === $imageData) {
                return [
                    'success' => false,
                    'message' => 'Invalid base64 data',
                ];
            }

            if ($user->avatar_url && Storage::disk('public')->exists(str_replace('/storage/', '', $user->avatar_url))) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $user->avatar_url));
            }

            $extension = 'jpg';
            if (str_starts_with($base64Data, 'data:image/svg+xml;')) {
                $extension = 'svg';
            } elseif (str_starts_with($base64Data, 'data:image/png;')) {
                $extension = 'png';
            } elseif (str_starts_with($base64Data, 'data:image/gif;')) {
                $extension = 'gif';
            } elseif (str_starts_with($base64Data, 'data:image/webp;')) {
                $extension = 'webp';
            }

            $filename = 'avatar_'.$user->id.'_'.time().'.'.$extension;
            $path = 'avatars/'.$filename;

            Storage::disk('public')->put($path, $imageData);

            $avatarUrl = '/storage/'.$path;
            $user->update(['avatar_url' => $avatarUrl]);

            return [
                'success' => true,
                'avatar_url' => $avatarUrl,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to process image: '.$e->getMessage(),
            ];
        }
    }
}
