<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Domain\Payment\Services\DataCrazyIntegrationService;
use App\Http\Controllers\Base\Controller;
use App\Http\Requests\Payment\DataCrazyCheckoutRequest;
use Illuminate\Http\JsonResponse;

class DataCrazyIntegrationController extends Controller
{
    public function __construct(
        private DataCrazyIntegrationService $integrationService
    ) {
    }

    public function checkout(DataCrazyCheckoutRequest $request): JsonResponse
    {
        $authenticationError = $this->authenticateDataCrazyRequest($request);
        if (null !== $authenticationError) {
            return $authenticationError;
        }

        $result = $this->integrationService->createCheckout($request->validated());

        return response()->json($result['body'], $result['status']);
    }

    private function authenticateDataCrazyRequest(DataCrazyCheckoutRequest $request): ?JsonResponse
    {
        $token = (string) config('services.datacrazy.integration_token', '');
        $hmacSecret = (string) config('services.datacrazy.hmac_secret', '');

        if ('' === $token && '' === $hmacSecret) {
            return response()->json([
                'success' => false,
                'message' => 'DataCrazy integration is not configured.',
            ], 503);
        }

        if ('' !== $hmacSecret && $this->hasValidHmacSignature($request, $hmacSecret)) {
            return null;
        }

        $bearerToken = (string) $request->bearerToken();
        if ('' !== $token && '' !== $bearerToken && hash_equals($token, $bearerToken)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid DataCrazy integration credentials.',
        ], 401);
    }

    private function hasValidHmacSignature(DataCrazyCheckoutRequest $request, string $hmacSecret): bool
    {
        $timestamp = (string) $request->header('X-DataCrazy-Timestamp', '');
        $signature = (string) $request->header('X-DataCrazy-Signature', '');

        if ('' === $timestamp || '' === $signature || !ctype_digit($timestamp)) {
            return false;
        }

        $maxSkew = max(60, (int) config('services.datacrazy.max_skew_seconds', 300));
        if (abs(time() - (int) $timestamp) > $maxSkew) {
            return false;
        }

        $providedSignature = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $hmacSecret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
