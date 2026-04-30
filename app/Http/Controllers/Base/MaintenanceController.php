<?php

declare(strict_types=1);

namespace App\Http\Controllers\Base;

use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;

class MaintenanceController extends Controller
{
    public function seedProdUsers(Request $request): JsonResponse
    {
        $key = env('MAINTENANCE_SEED_KEY');
        $provided = (string) $request->header('X-Seed-Key', '');
        if (!$key || ! hash_equals((string) $key, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ProductionTestUsersSeeder', '--force' => true]);
            return response()->json(['success' => true, 'output' => Artisan::output()]);
        } catch (\Throwable $e) {
            Log::error('Failed to seed production maintenance users', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Maintenance action failed'], 500);
        }
    }

    public function checkPassword(Request $request): JsonResponse
    {
        $key = env('MAINTENANCE_SEED_KEY');
        $provided = (string) $request->header('X-Seed-Key', '');
        if (!$key || ! hash_equals((string) $key, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->input('email'))->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $valid = Hash::check($request->input('password'), $user->password);
        return response()->json(['success' => true, 'password_valid' => $valid]);
    }

    public function forceResetPassword(Request $request): JsonResponse
    {
        $key = env('MAINTENANCE_SEED_KEY');
        $provided = (string) $request->header('X-Seed-Key', '');
        if (!$key || ! hash_equals((string) $key, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'email' => ['required', 'email'],
            'new_password' => ['nullable', 'string', 'min:8'],
        ]);

        $user = User::where('email', $request->input('email'))->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $newPassword = $request->string('new_password')->toString();
        if ($newPassword === '') {
            $newPassword = 'Tmp-' . Str::random(16);
        }

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'remember_token' => Str::random(60),
        ])->save();

        return response()->json([
            'success' => true,
            'email' => $user->email,
            'temporary_password' => $request->has('new_password') ? null : $newPassword,
            'message' => $request->has('new_password') ? 'Password updated' : 'Temporary password generated',
        ]);
    }

    public function forceResetPasswordPublic(Request $request): JsonResponse
    {
        return $this->forceResetPassword($request);
    }
}
