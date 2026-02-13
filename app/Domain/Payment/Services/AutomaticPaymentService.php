<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Contract\Contract;
use App\Models\Payment\JobPayment;
use Exception;
use Illuminate\Support\Facades\Log;

class AutomaticPaymentService
{
    public function processContractPayment(Contract $contract): array
    {
        try {
            Log::info('Processing automatic payment for contract', [
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'budget' => $contract->budget,
                'status' => $contract->status,
                'workflow_status' => $contract->workflow_status,
                'simulation_mode' => PaymentSimulator::isSimulationMode(),
            ]);

            if (!$contract->exists) {
                throw new Exception('Contract does not exist');
            }

            if (PaymentSimulator::isSimulationMode()) {
                Log::info('SIMULATION: Processing automatic payment in simulation mode', [
                    'contract_id' => $contract->id,
                    'simulation_mode' => true,
                ]);

                $simulationResult = PaymentSimulator::simulateContractPayment([
                    'amount' => $contract->budget,
                    'contract_id' => $contract->id,
                    'description' => 'Automatic Contract Payment: ' . $contract->title,
                ], $contract->brand);

                if (!$simulationResult['success']) {
                    throw new Exception($simulationResult['message'] ?? 'Simulation failed');
                }

                $payment = JobPayment::create([
                    'contract_id' => $contract->id,
                    'brand_id' => $contract->brand_id,
                    'creator_id' => $contract->creator_id,
                    'total_amount' => $contract->budget,
                    'creator_amount' => $contract->creator_amount,
                    'platform_fee' => $contract->platform_fee,
                    'payment_method' => 'credit_card',
                    'status' => 'completed',
                    'processed_at' => now(),
                    'transaction_id' => $simulationResult['transaction_id'],
                ]);

                if (!$payment) {
                    throw new Exception('Failed to create payment record');
                }

                $contract->update([
                    'status' => 'active',
                    'workflow_status' => 'active',
                ]);

                Log::info('SIMULATION: Automatic payment processed successfully', [
                    'contract_id' => $contract->id,
                    'payment_id' => $payment->id,
                    'transaction_id' => $simulationResult['transaction_id'],
                    'amount' => $contract->budget,
                    'new_status' => 'active',
                    'new_workflow_status' => 'active',
                    'simulation_mode' => true,
                ]);

                return [
                    'success' => true,
                    'message' => 'Payment processed successfully (SIMULATION)',
                    'payment_id' => $payment->id,
                    'transaction_id' => $simulationResult['transaction_id'],
                    'simulation' => true,
                ];
            }

            $payment = JobPayment::create([
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'total_amount' => $contract->budget,
                'creator_amount' => $contract->creator_amount,
                'platform_fee' => $contract->platform_fee,
                'payment_method' => 'credit_card',
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            if (!$payment) {
                throw new Exception('Failed to create payment record');
            }

            $contract->update([
                'status' => 'active',
                'workflow_status' => 'active',
            ]);

            Log::info('Payment processed successfully', [
                'contract_id' => $contract->id,
                'payment_id' => $payment->id,
                'amount' => $contract->budget,
                'new_status' => 'active',
                'new_workflow_status' => 'active',
            ]);

            return [
                'success' => true,
                'message' => 'Payment processed successfully',
                'payment_id' => $payment->id,
            ];
        } catch (Exception $e) {
            Log::error('Payment processing failed', [
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'simulation_mode' => PaymentSimulator::isSimulationMode(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage(),
            ];
        }
    }

    public function retryPayment(Contract $contract): array
    {
        return $this->processContractPayment($contract);
    }
}
