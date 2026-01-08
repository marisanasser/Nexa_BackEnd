<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\Common\Guide;
use App\Models\Common\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Storage;

/**
 * AdminGuideController handles admin guide management operations.
 *
 * Extracted from the monolithic AdminController for better separation of concerns.
 */
class AdminGuideController extends Controller
{
    use HasAuthenticatedUser;

    /**
     * Get all guides.
     */
    public function index(): JsonResponse
    {
        try {
            $guides = Guide::with('steps')->latest()->get();

            return response()->json([
                'success' => true,
                'data' => $guides,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch guides: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single guide.
     *
     * @param mixed $id
     */
    public function show($id): JsonResponse
    {
        try {
            $guide = Guide::with('steps')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $guide,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch guide: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a guide.
     *
     * @param mixed $id
     */
    public function update($id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|min:2|max:255',
                'audience' => 'required|string|in:Brand,Creator',
                'description' => 'required|string|min:10',
                'steps' => 'sometimes|array',
                'steps.*.title' => 'required_with:steps|string|min:2|max:255',
                'steps.*.description' => 'required_with:steps|string|min:10',
                'steps.*.videoFile' => 'sometimes|nullable|file|mimes:mp4,mov,avi,wmv,mpeg|max:81920',
            ]);

            $guide = Guide::findOrFail($id);

            $data = $request->only(['title', 'audience', 'description']);
            $data['video_path'] = null;
            $data['video_mime'] = null;

            DB::beginTransaction();

            $guide->update($data);

            if ($request->has('steps') && is_array($request->steps)) {
                $guide->steps()->delete();

                foreach ($request->steps as $index => $stepData) {
                    $stepFields = [
                        'guide_id' => $guide->id,
                        'title' => $stepData['title'],
                        'description' => $stepData['description'],
                        'order' => $index,
                    ];

                    if (isset($stepData['videoFile']) && $stepData['videoFile'] instanceof UploadedFile) {
                        $file = $stepData['videoFile'];
                        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
                        $path = $file->storeAs('videos/steps', $filename, config('filesystems.default'));

                        $stepFields['video_path'] = $path;
                        $stepFields['video_mime'] = $file->getMimeType();
                    }

                    if (isset($stepData['screenshots']) && is_array($stepData['screenshots'])) {
                        $screenshotPaths = [];
                        foreach ($stepData['screenshots'] as $screenshot) {
                            if ($screenshot instanceof UploadedFile) {
                                $filename = Str::uuid()->toString().'.'.$screenshot->getClientOriginalExtension();
                                $path = $screenshot->storeAs('screenshots/steps', $filename, config('filesystems.default'));
                                $screenshotPaths[] = $path;
                            }
                        }
                        $stepFields['screenshots'] = $screenshotPaths;
                    }

                    Step::create($stepFields);
                }
            }

            DB::commit();

            $guide->load('steps');

            return response()->json([
                'success' => true,
                'data' => $guide,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Guide update failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update guide: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a guide.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $guide = Guide::with('steps')->findOrFail($id);

            DB::beginTransaction();

            // Delete step files
            foreach ($guide->steps as $step) {
                if ($step->video_path) {
                    $this->deleteFile($step->video_path);
                }
                if ($step->screenshots && is_array($step->screenshots)) {
                    foreach ($step->screenshots as $screenshot) {
                        $this->deleteFile($screenshot);
                    }
                }
            }

            // Delete guide video
            if ($guide->video_path) {
                $this->deleteFile($guide->video_path);
            }

            // Delete steps and guide
            $guide->steps()->delete();
            $guide->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Guide deleted successfully',
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Guide deletion failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete guide: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a file from storage.
     */
    private function deleteFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        try {
            $disk = config('filesystems.default');
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (Exception $e) {
            Log::warning('Failed to delete file: '.$path.' - '.$e->getMessage());
        }
    }
}
