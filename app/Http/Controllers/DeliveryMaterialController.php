<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\DeliveryMaterial;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DeliveryMaterialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::findOrFail($request->contract_id);

        if (Auth::user()->role !== 'brand' || $contract->brand_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $materials = DeliveryMaterial::where('contract_id', $contract->id)
            ->with(['creator:id,name,avatar_url', 'milestone:id,title,milestone_type'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $materials,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator()) {
            return response()->json(['message' => 'Only creators can submit delivery materials'], 403);
        }

        $validator = Validator::make($request->all(), [
            'contract_id' => 'required|exists:contracts,id',
            'milestone_id' => 'nullable|exists:campaign_timelines,id',
            'file' => 'required|file|max:102400',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $contract = Contract::findOrFail($request->contract_id);

        if ($contract->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($contract->status !== 'active') {
            return response()->json(['message' => 'Contract is not active'], 400);
        }

        try {
            $file = $request->file('file');
            $fileName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $filePath = $file->storeAs('delivery-materials/' . $user->id, $fileName, config('filesystems.default'));

            $mimeType = $file->getMimeType();
            $mediaType = $this->getMediaType($mimeType);

            $material = DeliveryMaterial::create([
                'contract_id' => $contract->id,
                'creator_id' => $user->id,
                'brand_id' => $contract->brand_id,
                'milestone_id' => $request->milestone_id,
                'file_path' => $filePath,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $mimeType,
                'file_size' => $file->getSize(),
                'media_type' => $mediaType,
                'title' => $request->title,
                'description' => $request->description,
                'status' => 'pending',
                'submitted_at' => now(),
            ]);

            NotificationService::notifyBrandOfNewDeliveryMaterial($material);

            return response()->json([
                'success' => true,
                'message' => 'Delivery material submitted successfully',
                'data' => $material->load(['creator:id,name,avatar_url', 'milestone:id,title,milestone_type']),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to submit delivery material', [
                'user_id' => $user->id,
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit delivery material',
            ], 500);
        }
    }

    public function approve(Request $request, DeliveryMaterial $material): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand() || $material->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $material->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Material cannot be approved'], 400);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $material->approve($user->id, $request->comment);

            NotificationService::notifyCreatorOfDeliveryMaterialApproval($material);

            NotificationService::notifyBrandOfDeliveryMaterialAction($material, 'approved');

            return response()->json([
                'success' => true,
                'message' => 'Delivery material approved successfully',
                'data' => $material->fresh()->load(['creator:id,name,avatar_url', 'milestone:id,title,milestone_type']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to approve delivery material', [
                'user_id' => $user->id,
                'material_id' => $material->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve delivery material',
            ], 500);
        }
    }

    public function reject(Request $request, DeliveryMaterial $material): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand() || $material->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $material->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Material cannot be rejected'], 400);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500',
            'comment' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $material->reject($user->id, $request->rejection_reason, $request->comment);

            NotificationService::notifyCreatorOfDeliveryMaterialRejection($material);

            NotificationService::notifyBrandOfDeliveryMaterialAction($material, 'rejected');

            return response()->json([
                'success' => true,
                'message' => 'Delivery material rejected successfully',
                'data' => $material->fresh()->load(['creator:id,name,avatar_url', 'milestone:id,title,milestone_type']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reject delivery material', [
                'user_id' => $user->id,
                'material_id' => $material->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject delivery material',
            ], 500);
        }
    }

    public function download(DeliveryMaterial $material): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($material->contract->brand_id !== $user->id && $material->contract->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! Storage::disk('public')->exists($material->file_path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $filePath = Storage::disk('public')->path($material->file_path);
        $fileName = $material->file_name;

        return response()->download($filePath, $fileName);
    }

    public function getStatistics(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::findOrFail($request->contract_id);

        if (Auth::user()->role !== 'brand' || $contract->brand_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $statistics = [
            'total_materials' => DeliveryMaterial::where('contract_id', $contract->id)->count(),
            'pending_materials' => DeliveryMaterial::where('contract_id', $contract->id)->where('status', 'pending')->count(),
            'approved_materials' => DeliveryMaterial::where('contract_id', $contract->id)->where('status', 'approved')->count(),
            'rejected_materials' => DeliveryMaterial::where('contract_id', $contract->id)->where('status', 'rejected')->count(),
            'by_media_type' => [
                'images' => DeliveryMaterial::where('contract_id', $contract->id)->where('media_type', 'image')->count(),
                'videos' => DeliveryMaterial::where('contract_id', $contract->id)->where('media_type', 'video')->count(),
                'documents' => DeliveryMaterial::where('contract_id', $contract->id)->where('media_type', 'document')->count(),
                'other' => DeliveryMaterial::where('contract_id', $contract->id)->where('media_type', 'other')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }

    private function getMediaType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/rtf',
        ])) {
            return 'document';
        }

        return 'other';
    }
}
