<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankAccountRequest;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Register or update a bank account for the authenticated user.
     */
    public function registerBankAccount(StoreBankAccountRequest $request): JsonResponse
    {
        $user = auth()->user();

        Log::info('Registering bank account', ['user_id' => $user->id]);

        $data = $request->validated();
        $data['user_id'] = $user->id;

        // Check if user already has a bank account
        $bankAccount = BankAccount::where('user_id', $user->id)->first();

        if ($bankAccount) {
            $bankAccount->update($data);
            $message = 'Bank account updated successfully';
            $action = 'update';
        } else {
            $bankAccount = BankAccount::create($data);
            $message = 'Bank account registered successfully';
            $action = 'create';
        }

        Log::info("Bank account {$action}d", [
            'user_id' => $user->id,
            'bank_code' => $data['bank_code'],
            'agencia' => $data['agencia'],
            'conta_last4' => substr($data['conta'], -4),
            'cpf_masked' => substr($data['cpf'], 0, 3).'.***.***-'.substr($data['cpf'], -2),
        ]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $bankAccount,
        ]);
    }

    /**
     * Get the bank account information for the authenticated user.
     */
    public function getBankInfo(): JsonResponse
    {
        $user = auth()->user();
        $bankAccount = BankAccount::where('user_id', $user->id)->first();

        if (! $bankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'No bank account found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $bankAccount,
        ]);
    }

    /**
     * Update the bank account information (alias for register).
     */
    public function updateBankInfo(StoreBankAccountRequest $request): JsonResponse
    {
        return $this->registerBankAccount($request);
    }

    /**
     * Delete the bank account information.
     */
    public function deleteBankInfo(): JsonResponse
    {
        $user = auth()->user();
        $bankAccount = BankAccount::where('user_id', $user->id)->first();

        if (! $bankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'No bank account found',
            ], 404);
        }

        $bankAccount->delete();

        Log::info('Bank account deleted', ['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'message' => 'Bank account deleted successfully',
        ]);
    }

    /**
     * Debug endpoint (kept for reference but should be used with caution).
     */
    public function debugPayment(Request $request)
    {
        if (! config('app.debug')) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Debug payment endpoint',
            'data' => $request->all(),
        ]);
    }
}
