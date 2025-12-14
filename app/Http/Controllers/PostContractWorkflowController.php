<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PostContractWorkflowController extends Controller
{
    public function getContractsWaitingForReview(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can access this endpoint',
            ], 403);
        }

        try {
            $contracts = Contract::where('brand_id', $user->id)
                ->where('status', 'completed')
                ->where('workflow_status', 'waiting_review')
                ->with(['creator:id,name,avatar_url', 'review'])
                ->orderBy('completed_at', 'desc')
                ->get();

            $contracts->transform(function ($contract) {
                return [
                    'id' => $contract->id,
                    'title' => $contract->title,
                    'description' => $contract->description,
                    'budget' => $contract->formatted_budget,
                    'completed_at' => $contract->completed_at->format('Y-m-d H:i:s'),
                    'creator' => [
                        'id' => $contract->creator->id,
                        'name' => $contract->creator->name,
                        'avatar_url' => $contract->creator->avatar_url,
                    ],
                    'has_review' => $contract->review !== null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching contracts waiting for review', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contracts waiting for review',
            ], 500);
        }
    }

    public function getContractsWithPaymentAvailable(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators can access this endpoint',
            ], 403);
        }

        try {
            $contracts = Contract::where('creator_id', $user->id)
                ->where('status', 'completed')
                ->where('workflow_status', 'payment_available')
                ->with(['brand:id,name,avatar_url', 'payment', 'review'])
                ->orderBy('completed_at', 'desc')
                ->get();

            $contracts->transform(function ($contract) {
                return [
                    'id' => $contract->id,
                    'title' => $contract->title,
                    'description' => $contract->description,
                    'budget' => $contract->formatted_budget,
                    'creator_amount' => $contract->formatted_creator_amount,
                    'platform_fee' => $contract->formatted_platform_fee,
                    'completed_at' => $contract->completed_at->format('Y-m-d H:i:s'),
                    'brand' => [
                        'id' => $contract->brand->id,
                        'name' => $contract->brand->name,
                        'avatar_url' => $contract->brand->avatar_url,
                    ],
                    'payment' => [
                        'id' => $contract->payment->id,
                        'status' => $contract->payment->status,
                        'creator_amount' => $contract->payment->formatted_creator_amount,
                    ],
                    'review' => $contract->review ? [
                        'rating' => $contract->review->rating,
                        'comment' => $contract->review->comment,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching contracts with payment available', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contracts with payment available',
            ], 500);
        }
    }

    public function getWorkHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'type' => 'required|in:creator,brand',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::find($request->user_id);
            $type = $request->type;

            $query = Contract::where('status', 'completed')
                ->where('workflow_status', 'payment_withdrawn')
                ->with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url', 'review', 'payment']);

            if ($type === 'creator') {
                $query->where('creator_id', $user->id);
            } else {
                $query->where('brand_id', $user->id);
            }

            $contracts = $query->orderBy('completed_at', 'desc')
                ->paginate(10);

            $contracts->getCollection()->transform(function ($contract) use ($type) {
                $otherUser = $type === 'creator' ? $contract->brand : $contract->creator;

                return [
                    'id' => $contract->id,
                    'title' => $contract->title,
                    'description' => $contract->description,
                    'budget' => $contract->formatted_budget,
                    'completed_at' => $contract->completed_at->format('Y-m-d H:i:s'),
                    'duration_days' => $contract->estimated_days,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'avatar_url' => $otherUser->avatar_url,
                    ],
                    'review' => $contract->review ? [
                        'rating' => $contract->review->rating,
                        'comment' => $contract->review->comment,
                        'created_at' => $contract->review->created_at->format('Y-m-d H:i:s'),
                    ] : null,
                    'payment' => $contract->payment ? [
                        'creator_amount' => $contract->payment->formatted_creator_amount,
                        'platform_fee' => $contract->payment->formatted_platform_fee,
                    ] : null,
                ];
            });

            $stats = $this->calculateWorkHistoryStats($user, $type);

            return response()->json([
                'success' => true,
                'data' => [
                    'contracts' => $contracts,
                    'statistics' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching work history', [
                'user_id' => $request->user_id,
                'type' => $request->type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch work history',
            ], 500);
        }
    }

    private function calculateWorkHistoryStats(User $user, string $type): array
    {
        $query = Contract::where('status', 'completed')
            ->where('workflow_status', 'payment_withdrawn');

        if ($type === 'creator') {
            $query->where('creator_id', $user->id);
        } else {
            $query->where('brand_id', $user->id);
        }

        $totalContracts = $query->count();
        $totalEarnings = $type === 'creator' ? $query->sum('creator_amount') : $query->sum('budget');

        $reviews = $query->with('review')->get()->pluck('review')->filter();
        $averageRating = $reviews->count() > 0 ? $reviews->avg('rating') : 0;

        return [
            'total_contracts' => $totalContracts,
            'total_earnings' => $type === 'creator' ? 'R$ '.number_format($totalEarnings, 2, ',', '.') : 'R$ '.number_format($totalEarnings, 2, ',', '.'),
            'average_rating' => round($averageRating, 1),
            'total_reviews' => $reviews->count(),
        ];
    }
}
