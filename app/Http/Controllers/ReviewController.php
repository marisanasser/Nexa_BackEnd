<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class ReviewController extends Controller
{
    /**
     * Create a review for a completed contract
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contract_id' => 'required|integer|exists:contracts,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'rating_categories' => 'sometimes|array',
            'rating_categories.communication' => 'sometimes|integer|min:1|max:5',
            'rating_categories.quality' => 'sometimes|integer|min:1|max:5',
            'rating_categories.timeliness' => 'sometimes|integer|min:1|max:5',
            'rating_categories.professionalism' => 'sometimes|integer|min:1|max:5',
            'is_public' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        // Log user role for debugging
        Log::info('Review creation attempt', [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'is_brand' => $user->isBrand(),
            'is_creator' => $user->isCreator(),
            'contract_id' => $request->contract_id,
        ]);

        try {
            // Find contract and check if user is involved (as brand or creator)
            // This allows users to review if they're involved in the contract, regardless of role field
            $contract = Contract::where('status', 'completed')
                ->where(function($query) use ($user) {
                    $query->where('brand_id', $user->id)
                          ->orWhere('creator_id', $user->id);
                })
                    ->find($request->contract_id);
            
            // If contract not found, user is not involved in this contract
            if (!$contract) {
                Log::warning('Review creation blocked - user is not involved in contract', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'contract_id' => $request->contract_id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Contract not found or you are not authorized to review this contract',
                ], 403);
            }

            Log::info('Review submission attempt', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'contract_id' => $request->contract_id,
                'contract_found' => !!$contract,
                'contract_status' => $contract?->status,
                'has_creator_review' => $contract?->has_creator_review,
                'has_brand_review' => $contract?->has_brand_review,
            ]);

            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contract not found or cannot be reviewed',
                ], 404);
            }

            // Check if review already exists
            $existingReview = Review::where('contract_id', $contract->id)
                ->where('reviewer_id', $user->id)
                ->first();

            if ($existingReview) {
                Log::warning('Duplicate review attempt blocked', [
                    'user_id' => $user->id,
                    'contract_id' => $contract->id,
                    'existing_review_id' => $existingReview->id,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reviewed this contract',
                ], 400);
            }

            // Check if both parties have already reviewed each other
            if ($contract->hasBothReviews()) {
                Log::warning('Both parties already reviewed attempt blocked', [
                    'user_id' => $user->id,
                    'contract_id' => $contract->id,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Both parties have already reviewed this contract',
                ], 400);
            }

            // Determine who is being reviewed based on user's involvement in contract
            // If user is the brand, they're reviewing the creator, and vice versa
            $reviewedId = ($contract->brand_id === $user->id) ? $contract->creator_id : $contract->brand_id;
            
            Log::info('Creating review', [
                'contract_id' => $contract->id,
                'reviewer_id' => $user->id,
                'reviewed_id' => $reviewedId,
                'rating' => $request->rating,
            ]);
            
            $review = Review::create([
                'contract_id' => $contract->id,
                'reviewer_id' => $user->id,
                'reviewed_id' => $reviewedId,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'rating_categories' => $request->rating_categories,
                'is_public' => $request->get('is_public', true),
            ]);

            // Note: Review statistics are automatically updated via the Review model's booted method

            // Update contract review status
            try {
                $contract->updateReviewStatus();
            } catch (\Exception $e) {
                Log::error('Failed to update contract review status', [
                    'review_id' => $review->id,
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Process payment after review is submitted
            if ($contract->processPaymentAfterReview()) {
                Log::info('Payment processed after review submission', [
                    'contract_id' => $contract->id,
                    'payment_id' => $contract->payment->id,
                    'creator_amount' => $contract->creator_amount,
                    'platform_fee' => $contract->platform_fee,
                ]);
            }

            Log::info('Review created successfully', [
                'review_id' => $review->id,
                'contract_id' => $contract->id,
                'reviewer_id' => $user->id,
                'reviewed_id' => $reviewedId,
                'reviewer_role' => $user->role,
                'rating' => $request->rating,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully! Payment has been processed and is now available for withdrawal.',
                'data' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'average_rating' => $review->average_rating,
                    'comment' => $review->comment,
                    'rating_categories' => $review->rating_categories,
                    'is_public' => $review->is_public,
                    'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                    'payment_processed' => $contract->isPaymentAvailable(),
                    'creator_amount' => $contract->creator_amount,
                    'platform_fee' => $contract->platform_fee,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating review', [
                'user_id' => $user->id,
                'contract_id' => $request->contract_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review. Please try again.',
            ], 500);
        }
    }

    /**
     * Get reviews for a creator
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'rating' => 'nullable|integer|min:1|max:5',
            'public_only' => 'nullable|string|in:true,false,1,0',
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
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $query = Review::where('reviewed_id', $user->id)
                ->with(['reviewer:id,name,avatar_url', 'contract:id,title']);

            $publicOnly = $request->get('public_only', 'true');
            if ($publicOnly === 'true' || $publicOnly === '1' || $publicOnly === true) {
                $query->where('is_public', true);
            }

            if ($request->rating) {
                $query->where('rating', $request->rating);
            }

            $reviews = $query->orderBy('created_at', 'desc')
                ->paginate(10);

            $reviews->getCollection()->transform(function ($review) {
                return [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'average_rating' => $review->average_rating,
                    'rating_stars' => $review->rating_stars,
                    'formatted_rating' => $review->formatted_rating,
                    'rating_category' => $review->rating_category,
                    'rating_color' => $review->rating_color,
                    'comment' => $review->comment,
                    'rating_categories' => $review->rating_categories,
                    'is_public' => $review->is_public,
                    'is_high_rating' => $review->isHighRating(),
                    'reviewer' => [
                        'id' => $review->reviewer?->id,
                        'name' => $review->reviewer?->name,
                        'avatar_url' => $review->reviewer?->avatar_url,
                    ],
                    'contract' => [
                        'id' => $review->contract?->id,
                        'title' => $review->contract?->title,
                    ],
                    'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                ];
            });

            // Get rating distribution first
            $ratingDistribution = $this->getRatingDistribution($user->id, $publicOnly === 'true' || $publicOnly === '1' || $publicOnly === true);
            
            // Calculate total reviews from distribution
            $totalReviewsFromDistribution = array_sum($ratingDistribution);
            
            
            // Get user stats - use distribution total if it's different from cached value
            $stats = [
                'average_rating' => $user->average_rating ?? 0,
                'total_reviews' => $totalReviewsFromDistribution > 0 ? $totalReviewsFromDistribution : ($user->total_reviews ?? 0),
                'rating_distribution' => $ratingDistribution,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'reviews' => $reviews,
                    'stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching reviews', [
                'user_id' => $request->user_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews',
            ], 500);
        }
    }

    /**
     * Get a specific review
     */
    public function show(int $id): JsonResponse
    {
        try {
            $review = Review::with(['reviewer:id,name,avatar_url', 'reviewed:id,name,avatar_url', 'contract:id,title'])
                ->find($id);

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'average_rating' => $review->average_rating,
                    'rating_stars' => $review->rating_stars,
                    'formatted_rating' => $review->formatted_rating,
                    'rating_category' => $review->rating_category,
                    'rating_color' => $review->rating_color,
                    'comment' => $review->comment,
                    'rating_categories' => $review->rating_categories,
                    'is_public' => $review->is_public,
                    'is_high_rating' => $review->isHighRating(),
                    'is_low_rating' => $review->isLowRating(),
                    'reviewer' => [
                        'id' => $review->reviewer?->id,
                        'name' => $review->reviewer?->name,
                        'avatar_url' => $review->reviewer?->avatar_url,
                    ],
                    'reviewed' => [
                        'id' => $review->reviewed?->id,
                        'name' => $review->reviewed?->name,
                        'avatar_url' => $review->reviewed?->avatar_url,
                    ],
                    'contract' => [
                        'id' => $review->contract?->id,
                        'title' => $review->contract?->title,
                    ],
                    'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching review', [
                'review_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch review',
            ], 500);
        }
    }

    /**
     * Update a review
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'nullable|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'rating_categories' => 'nullable|array',
            'rating_categories.communication' => 'nullable|integer|min:1|max:5',
            'rating_categories.quality' => 'nullable|integer|min:1|max:5',
            'rating_categories.timeliness' => 'nullable|integer|min:1|max:5',
            'rating_categories.professionalism' => 'nullable|integer|min:1|max:5',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        try {
            $review = Review::where('reviewer_id', $user->id)
                ->find($id);

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found or access denied',
                ], 404);
            }

            $review->update($request->only([
                'rating', 'comment', 'rating_categories', 'is_public'
            ]));

            Log::info('Review updated successfully', [
                'review_id' => $review->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully',
                'data' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'average_rating' => $review->average_rating,
                    'comment' => $review->comment,
                    'rating_categories' => $review->rating_categories,
                    'is_public' => $review->is_public,
                    'updated_at' => $review->updated_at->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating review', [
                'user_id' => $user->id,
                'review_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update review. Please try again.',
            ], 500);
        }
    }

    /**
     * Delete a review
     */
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();

        try {
            $review = Review::where('reviewer_id', $user->id)
                ->find($id);

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found or access denied',
                ], 404);
            }

            $review->delete();

            Log::info('Review deleted successfully', [
                'review_id' => $id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting review', [
                'user_id' => $user->id,
                'review_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review. Please try again.',
            ], 500);
        }
    }

    /**
     * Get contract review status
     */
    public function getContractReviewStatus(int $contractId): JsonResponse
    {
        try {
            $user = Auth::user();
            $contract = Contract::find($contractId);

            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contract not found',
                ], 404);
            }

            // Check if user is involved in this contract
            if ($contract->brand_id !== $user->id && $contract->creator_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied',
                ], 403);
            }

            $data = [
                'contract_id' => $contract->id,
                'has_brand_review' => $contract->hasBrandReview(),
                'has_creator_review' => $contract->hasCreatorReview(),
                'has_both_reviews' => $contract->hasBothReviews(),
                'can_review' => false,
                'review_message' => '',
            ];

            // Determine if current user can review
            if ($user->id === $contract->brand_id) {
                $data['can_review'] = !$contract->hasBrandReview() && $contract->status === 'completed';
                $data['review_message'] = $contract->hasBrandReview() 
                    ? 'You have already reviewed this contract' 
                    : 'You can review this contract';
            } elseif ($user->id === $contract->creator_id) {
                $data['can_review'] = !$contract->hasCreatorReview() && $contract->status === 'completed';
                $data['review_message'] = $contract->hasCreatorReview() 
                    ? 'You have already reviewed this contract' 
                    : 'You can review this contract';
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting contract review status', [
                'contract_id' => $contractId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get review status',
            ], 500);
        }
    }

    /**
     * Get rating distribution for a creator
     */
    private function getRatingDistribution(int $creatorId, bool $publicOnly = true): array
    {
        $query = Review::where('reviewed_id', $creatorId);
        
        if ($publicOnly) {
            $query->where('is_public', true);
        }

        $distribution = $query->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get()
            ->keyBy('rating');

        $result = [];
        for ($i = 5; $i >= 1; $i--) {
            $result[$i] = $distribution[$i]->count ?? 0;
        }

        return $result;
    }

    /**
     * Send NEXA review request message when both parties have reviewed
     */
    // private function sendNexaReviewRequest($contract): void
    // {
    //     try {
    //         $chatRoom = $contract->offer->chatRoom;
    //         $brand = $contract->brand;
    //         $creator = $contract->creator;

    //         // Message asking for NEXA review
    //         $nexaReviewMessage = "🌟 Thank you for completing this campaign!\n\n" .
    //             "Both parties have submitted their reviews and the payment has been processed successfully. " .
    //             "We hope you had a great experience working together!\n\n" .
    //             "To help us improve our platform and provide better service to all users, " .
    //             "we would greatly appreciate if you could take a moment to review NEXA on your preferred platform:\n\n" .
    //             "• Google Play Store\n" .
    //             "• App Store\n" .
    //             "• Trustpilot\n" .
    //             "• Your social media channels\n\n" .
    //             "Your feedback helps us grow and serve our community better. Thank you for being part of NEXA! 🚀";

    //         \App\Models\Message::create([
    //             'chat_room_id' => $chatRoom->id,
    //             'sender_id' => $brand->id,
    //             'message' => $nexaReviewMessage,
    //             'message_type' => 'text',
    //             'is_system_message' => true,
    //         ]);

    //         Log::info('NEXA review request sent successfully', [
    //             'contract_id' => $contract->id,
    //             'chat_room_id' => $chatRoom->id,
    //             'brand_id' => $brand->id,
    //             'creator_id' => $creator->id,
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error('Failed to send NEXA review request', [
    //             'contract_id' => $contract->id,
    //             'error' => $e->getMessage(),
    //         ]);
    //     }
    // }
} 