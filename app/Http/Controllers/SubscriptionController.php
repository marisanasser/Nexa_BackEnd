<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function getPlans(): JsonResponse
    {
        try {
            $plans = SubscriptionPlan::getActivePlans();
            \Log::info('Subscription plans', ['plans' => $plans]);

            return response()->json([
                'success' => true,
                'data' => $plans->map(function ($plan) {
                    return [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'price' => $plan->price,
                        'duration_months' => $plan->duration_months,
                        'monthly_price' => $plan->monthly_price,
                        'savings_percentage' => $plan->savings_percentage,
                        'features' => $plan->features,
                        'sort_order' => $plan->sort_order,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving subscription plans', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving subscription plans. Please try again.',
            ], 500);
        }
    }

    public function getSubscriptionHistory(): JsonResponse
    {
        try {
            $user = auth()->user();
            \Log::info('Subscription history', ['user' => $user]);
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $subscriptions = $user->subscriptions()
                ->with('plan')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($subscription) {
                    return [
                        'id' => $subscription->id,
                        'plan_name' => $subscription->plan->name,
                        'status' => $subscription->status,
                        'amount_paid' => $subscription->amount_paid,
                        'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                        'expires_at' => $subscription->expires_at?->format('Y-m-d H:i:s'),
                        'remaining_days' => $subscription->remaining_days,
                        'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                        'cancelled_at' => $subscription->cancelled_at?->format('Y-m-d H:i:s'),
                    ];
                });
            \Log::info('Subscription history', ['subscriptions' => $subscriptions]);

            return response()->json([
                'success' => true,
                'data' => $subscriptions,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving subscription history', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving subscription history. Please try again.',
            ], 500);
        }
    }

    public function cancelSubscription(): JsonResponse
    {
        try {
            $user = auth()->user();
            \Log::info('Cancel subscription', ['user' => $user]);
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }
            \Log::info('Cancel subscription', ['activeSubscription' => $activeSubscription]);
            $activeSubscription = $user->activeSubscription;
            \Log::info('Cancel subscription', ['activeSubscription' => $activeSubscription]);
            if (! $activeSubscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription found',
                ], 404);
            }
            \Log::info('Cancel subscription', ['activeSubscription' => $activeSubscription]);
            $activeSubscription->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            Log::info('Subscription cancelled', [
                'user_id' => $user->id,
                'subscription_id' => $activeSubscription->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error cancelling subscription', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error cancelling subscription. Please try again.',
            ], 500);
        }
    }
}
