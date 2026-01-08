<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;

class BrandRankingController extends Controller
{
    public function getBrandRankings(): JsonResponse
    {
        try {
            Log::info('Starting brand rankings calculation');

            $rankings = [
                'mostPosted' => $this->getMostPostedBrands(),
                'mostHired' => $this->getMostHiredBrands(),
                'mostPaid' => $this->getMostPaidBrands(),
            ];

            Log::info('Brand rankings calculated successfully', [
                'mostPostedCount' => count($rankings['mostPosted']),
                'mostHiredCount' => count($rankings['mostHired']),
                'mostPaidCount' => count($rankings['mostPaid']),
            ]);

            return response()->json([
                'success' => true,
                'data' => $rankings,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch brand rankings', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch brand rankings: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getComprehensiveRankings(): JsonResponse
    {
        try {
            Log::info('Starting comprehensive brand rankings calculation');

            $brands = User::where('role', 'brand')
                ->withCount([
                    'campaigns as total_campaigns',
                    'brandContracts as total_contracts' => function ($query): void {
                        $query->where('status', 'completed');
                    },
                ])
                ->withSum(['brandPayments as total_paid' => function ($query): void {
                    $query->where('status', 'completed');
                }], 'total_amount')
                ->get()
                ->filter(fn ($brand) => $brand->total_campaigns > 0 || $brand->total_contracts > 0 || ($brand->total_paid ?? 0) > 0)
                ->map(fn ($brand, $index) => [
                    'rank' => $index + 1,
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'company_name' => $brand->company_name,
                    'display_name' => $brand->company_name ?: $brand->name,
                    'total_campaigns' => $brand->total_campaigns,
                    'total_contracts' => $brand->total_contracts,
                    'total_paid' => (float) ($brand->total_paid ?? 0),
                    'total_paid_formatted' => 'R$ '.number_format($brand->total_paid ?? 0, 2, ',', '.'),
                    'avatar_url' => $brand->avatar_url,
                    'has_premium' => $brand->has_premium,
                    'created_at' => $brand->created_at,
                    'score' => $this->calculateRankingScore($brand->total_campaigns, $brand->total_contracts, $brand->total_paid ?? 0),
                ])
                ->sortByDesc('score')
                ->values()
                ->map(function ($brand, $index) {
                    $brand['rank'] = $index + 1;

                    return $brand;
                })
                ->take(20)
                ->toArray()
            ;

            Log::info('Comprehensive brand rankings calculated successfully', [
                'totalBrands' => count($brands),
            ]);

            return response()->json([
                'success' => true,
                'data' => $brands,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch comprehensive brand rankings', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch comprehensive brand rankings: '.$e->getMessage(),
            ], 500);
        }
    }

    private function getMostPostedBrands(): array
    {
        try {
            $brands = User::where('role', 'brand')
                ->withCount(['campaigns as total_campaigns'])
                ->get()
                ->filter(fn ($brand) => $brand->total_campaigns > 0)
                ->sortByDesc('total_campaigns')
                ->take(10)
                ->map(fn ($brand, $index) => [
                    'rank' => $index + 1,
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'company_name' => $brand->company_name,
                    'display_name' => $brand->company_name ?: $brand->name,
                    'total_campaigns' => $brand->total_campaigns,
                    'avatar_url' => $brand->avatar_url,
                    'has_premium' => $brand->has_premium,
                    'created_at' => $brand->created_at,
                ])
                ->toArray()
            ;

            Log::info('Most posted brands calculated', ['count' => count($brands)]);

            return $brands;
        } catch (Exception $e) {
            Log::error('Error calculating most posted brands', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function getMostHiredBrands(): array
    {
        try {
            $brands = User::where('role', 'brand')
                ->withCount([
                    'brandContracts as total_contracts' => function ($query): void {
                        $query->where('status', 'completed');
                    },
                ])
                ->get()
                ->filter(fn ($brand) => $brand->total_contracts > 0)
                ->sortByDesc('total_contracts')
                ->take(10)
                ->map(fn ($brand, $index) => [
                    'rank' => $index + 1,
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'company_name' => $brand->company_name,
                    'display_name' => $brand->company_name ?: $brand->name,
                    'total_contracts' => $brand->total_contracts,
                    'avatar_url' => $brand->avatar_url,
                    'has_premium' => $brand->has_premium,
                    'created_at' => $brand->created_at,
                ])
                ->toArray()
            ;

            Log::info('Most hired brands calculated', ['count' => count($brands)]);

            return $brands;
        } catch (Exception $e) {
            Log::error('Error calculating most hired brands', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function getMostPaidBrands(): array
    {
        try {
            $brands = User::where('role', 'brand')
                ->withSum(['brandPayments as total_paid' => function ($query): void {
                    $query->where('status', 'completed');
                }], 'total_amount')
                ->get()
                ->filter(fn ($brand) => $brand->total_paid > 0)
                ->sortByDesc('total_paid')
                ->take(10)
                ->map(fn ($brand, $index) => [
                    'rank' => $index + 1,
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'company_name' => $brand->company_name,
                    'display_name' => $brand->company_name ?: $brand->name,
                    'total_paid' => (float) $brand->total_paid,
                    'total_paid_formatted' => 'R$ '.number_format($brand->total_paid, 2, ',', '.'),
                    'avatar_url' => $brand->avatar_url,
                    'has_premium' => $brand->has_premium,
                    'created_at' => $brand->created_at,
                ])
                ->toArray()
            ;

            Log::info('Most paid brands calculated', ['count' => count($brands)]);

            return $brands;
        } catch (Exception $e) {
            Log::error('Error calculating most paid brands', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function calculateRankingScore(int $campaigns, int $contracts, float $payments): float
    {
        $campaignScore = min($campaigns / 10, 1) * 30;
        $contractScore = min($contracts / 20, 1) * 30;
        $paymentScore = min($payments / 10000, 1) * 40;

        return $campaignScore + $contractScore + $paymentScore;
    }
}
