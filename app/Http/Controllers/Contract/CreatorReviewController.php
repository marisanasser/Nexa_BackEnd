<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Models\Contract\Contract;
use Illuminate\Http\JsonResponse;

class CreatorReviewController extends Controller
{
    public function getCreatorReviews(): JsonResponse
    {
        try {
            Log::info('Starting creator reviews calculation');

            $reviews = Contract::where('status', 'completed')
                ->with(['creator', 'brand'])
                ->get()
                ->map(fn ($contract) => [
                    'contract_id' => $contract->id,
                    'creator_name' => $contract->creator->name,
                    'brand_name' => $contract->brand->name,
                    'rating' => $contract->rating,
                    'review' => $contract->review,
                    'created_at' => $contract->created_at,
                ])
            ;

            Log::info('Creator reviews calculated successfully', [
                'reviewCount' => count($reviews),
            ]);

            return response()->json([
                'success' => true,
                'data' => $reviews,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch creator reviews', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch creator reviews: '.$e->getMessage(),
            ], 500);
        }
    }
}
