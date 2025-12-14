<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WithdrawalMethodController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $methods = WithdrawalMethod::orderBy('sort_order')->get();

            return response()->json([
                'success' => true,
                'data' => $methods,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal methods', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal methods',
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:withdrawal_methods,code|max:50',
            'name' => 'required|string|max:100',
            'description' => 'required|string',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'required|numeric|min:0|gt:min_amount',
            'processing_time' => 'required|string|max:100',
            'fee' => 'required|numeric|min:0',
            'is_active' => 'boolean',
            'required_fields' => 'array',
            'field_config' => 'array',
            'sort_order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $method = WithdrawalMethod::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal method created successfully',
                'data' => $method,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating withdrawal method', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create withdrawal method',
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $method = WithdrawalMethod::find($id);

        if (! $method) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal method not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'string|unique:withdrawal_methods,code,'.$id.'|max:50',
            'name' => 'string|max:100',
            'description' => 'string',
            'min_amount' => 'numeric|min:0',
            'max_amount' => 'numeric|min:0',
            'processing_time' => 'string|max:100',
            'fee' => 'numeric|min:0',
            'is_active' => 'boolean',
            'required_fields' => 'array',
            'field_config' => 'array',
            'sort_order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $method->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal method updated successfully',
                'data' => $method,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating withdrawal method', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update withdrawal method',
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $method = WithdrawalMethod::find($id);

        if (! $method) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal method not found',
            ], 404);
        }

        try {
            $method->delete();

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal method deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting withdrawal method', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete withdrawal method',
            ], 500);
        }
    }

    public function toggleActive(int $id): JsonResponse
    {
        $method = WithdrawalMethod::find($id);

        if (! $method) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal method not found',
            ], 404);
        }

        try {
            $method->update(['is_active' => ! $method->is_active]);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal method status updated successfully',
                'data' => $method,
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling withdrawal method status', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update withdrawal method status',
            ], 500);
        }
    }
}
