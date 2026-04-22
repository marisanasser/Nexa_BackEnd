<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Services;

use App\Models\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaConversionsApiService
{
    public function isEnabled(): bool
    {
        $pixelId = (string) config('services.meta.pixel_id');
        $accessToken = (string) config('services.meta.access_token');

        return '' !== $pixelId && '' !== $accessToken;
    }

    public function trackCompleteRegistration(
        ?User $user,
        string $eventId,
        array $context = []
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        if ('' === trim($eventId)) {
            Log::warning('Meta CAPI CompleteRegistration skipped because event_id is empty');

            return;
        }

        $pixelId = (string) config('services.meta.pixel_id');
        $accessToken = (string) config('services.meta.access_token');
        $apiVersion = (string) config('services.meta.api_version', 'v22.0');
        $testEventCode = (string) config('services.meta.test_event_code');

        $event = [
            'event_name' => 'CompleteRegistration',
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'user_data' => $this->buildUserData($user, $eventId, $context),
            'custom_data' => [
                'status' => true,
                'content_name' => (string) ($context['content_name'] ?? 'Registro de marca'),
            ],
        ];

        if (!empty($context['event_source_url'])) {
            $event['event_source_url'] = (string) $context['event_source_url'];
        }

        $payload = [
            'data' => [$event],
            'access_token' => $accessToken,
        ];

        if ('' !== trim($testEventCode)) {
            $payload['test_event_code'] = $testEventCode;
        }

        $endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            $apiVersion,
            $pixelId
        );

        try {
            $response = Http::asJson()
                ->timeout(12)
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('Meta CAPI CompleteRegistration event failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'event_id' => $eventId,
                ]);

                return;
            }

            Log::info('Meta CAPI CompleteRegistration event sent', [
                'event_id' => $eventId,
                'response' => $response->json(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Meta CAPI CompleteRegistration event exception', [
                'event_id' => $eventId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function buildUserData(?User $user, string $eventId, array $context = []): array
    {
        $userData = [];

        if ($user && !empty($user->email)) {
            $userData['em'] = [$this->normalizeAndHash((string) $user->email)];
        }

        if ($user) {
            $userData['external_id'] = [$this->normalizeAndHash((string) $user->id)];
        }

        if (!empty($context['client_user_agent'])) {
            $userData['client_user_agent'] = (string) $context['client_user_agent'];
        }

        if (!empty($context['client_ip_address'])) {
            $userData['client_ip_address'] = (string) $context['client_ip_address'];
        }

        if (!empty($context['fbp'])) {
            $userData['fbp'] = (string) $context['fbp'];
        }

        if (!empty($context['fbc'])) {
            $userData['fbc'] = (string) $context['fbc'];
        }

        if (empty($userData)) {
            $userData['external_id'] = [$this->normalizeAndHash($eventId)];
        }

        return $userData;
    }

    private function normalizeAndHash(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        return hash('sha256', $normalized);
    }
}
