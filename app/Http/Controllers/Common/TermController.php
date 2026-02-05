<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Base\Controller;
use App\Models\Common\TermAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $keys = explode(',', $request->query('keys', ''));

        $accepted = TermAcceptance::where('user_id', $user->id)
            ->whereIn('term_key', $keys)
            ->pluck('term_key')
            ->toArray();

        $result = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if ($key) {
                $result[$key] = in_array($key, $accepted);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        
        $request->validate([
            'term_key' => 'required|string',
            'version' => 'nullable|string',
        ]);

        $termKey = $request->input('term_key');
        $version = $request->input('version', '1.0');

        $acceptance = TermAcceptance::firstOrCreate(
            [
                'user_id' => $user->id,
                'term_key' => $termKey,
                'version' => $version,
            ],
            [
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Term accepted successfully',
            'data' => $acceptance,
        ]);
    }
}
