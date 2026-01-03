<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuideRequest;
use App\Http\Resources\GuideResource;
use App\Models\Guide;
use App\Models\Step;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GuideController extends Controller
{
    public function index()
    {
        $guides = Guide::with('steps')->latest()->paginate(15);

        return GuideResource::collection($guides);
    }

    public function store(StoreGuideRequest $request)
    {
        try {
            Log::info('Guide Create Request - All data:', $request->all());
            Log::info('Guide Create Request - Has videoFile:', ['hasFile' => $request->hasFile('videoFile')]);
            Log::info('Guide Create Request - videoFile value:', ['videoFile' => $request->input('videoFile')]);
            Log::info('Guide Create Request - Files:', $request->allFiles());

            $data = $request->only(['title', 'audience', 'description']);

            if (! auth()->check()) {
                Log::error('User not authenticated for guide creation');

                return response()->json(['message' => 'User not authenticated'], 401);
            }

            $data['created_by'] = auth()->id();
            Log::info('Guide Create Request - User ID:', ['user_id' => $data['created_by']]);

            $data['video_path'] = null;
            $data['video_mime'] = null;

            DB::beginTransaction();

            $guide = Guide::create($data);
            Log::info('Guide created successfully:', ['guide_id' => $guide->id]);

            if ($request->has('steps') && is_array($request->steps)) {
                foreach ($request->steps as $index => $stepData) {
                    Log::info("Processing step {$index} complete data:", $stepData);
                    $stepFields = [
                        'guide_id' => $guide->id,
                        'title' => $stepData['title'],
                        'description' => $stepData['description'],
                        'order' => $index,
                    ];

                    if (isset($stepData['videoFile']) && $stepData['videoFile'] instanceof \Illuminate\Http\UploadedFile) {
                        $file = $stepData['videoFile'];
                        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
                        $path = $file->storeAs('videos/steps', $filename, config('filesystems.default'));

                        $stepFields['video_path'] = $path;
                        $stepFields['video_mime'] = $file->getMimeType();
                    }

                    if (isset($stepData['screenshots']) && is_array($stepData['screenshots'])) {
                        $screenshotPaths = [];
                        foreach ($stepData['screenshots'] as $screenshot) {
                            if ($screenshot instanceof \Illuminate\Http\UploadedFile) {
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

            return (new GuideResource($guide))
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Guide creation failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create guide',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Guide $guide)
    {
        $guide->load('steps');

        return new GuideResource($guide);
    }

    public function update(StoreGuideRequest $request, Guide $guide)
    {
        try {
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

                    if (isset($stepData['videoFile']) && $stepData['videoFile'] instanceof \Illuminate\Http\UploadedFile) {
                        $file = $stepData['videoFile'];
                        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
                        $path = $file->storeAs('videos/steps', $filename, config('filesystems.default'));

                        $stepFields['video_path'] = $path;
                        $stepFields['video_mime'] = $file->getMimeType();
                    }

                    if (isset($stepData['screenshots']) && is_array($stepData['screenshots'])) {
                        $screenshotPaths = [];
                        foreach ($stepData['screenshots'] as $screenshot) {
                            if ($screenshot instanceof \Illuminate\Http\UploadedFile) {
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

            return new GuideResource($guide);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Guide update failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to update guide',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Guide $guide)
    {
        try {
            DB::beginTransaction();

            foreach ($guide->steps as $step) {
                if ($step->video_path && Storage::disk(config('filesystems.default'))->exists($step->video_path)) {
                    Storage::disk(config('filesystems.default'))->delete($step->video_path);
                }
            }

            if ($guide->video_path && Storage::disk(config('filesystems.default'))->exists($guide->video_path)) {
                Storage::disk(config('filesystems.default'))->delete($guide->video_path);
            }

            $guide->steps()->delete();

            $guide->delete();

            DB::commit();

            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Guide deletion failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to delete guide',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
