<?php

namespace App\Http\Controllers\Contract;

use App\Events\Chat\NewMessage;
use App\Events\Contract\ContractActivated;
use App\Events\Contract\ContractCompleted;
use App\Events\Contract\ContractTerminated;
use App\Http\Controllers\Base\Controller;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\Contract\Contract;
use App\Models\User\User;
use App\Traits\OfferChatMessageTrait;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ContractController extends Controller
{
    use OfferChatMessageTrait;

    /**
     * @var array<int, string>
     */
    private const LOGISTICS_WORKFLOW_STATUSES = [
        'alignment_preparation',
        'material_sent',
        'product_sent',
        'product_received',
        'production_started',
    ];

    /**
     * @var array<int, string>
     */
    private const SHIPPING_WORKFLOW_STATUSES = [
        'material_sent',
        'product_sent',
    ];

    private function createSystemMessage(ChatRoom $chatRoom, string $message, array $data = []): void
    {
        try {
            $messageData = [
                'chat_room_id' => $chatRoom->id,
                'sender_id' => null,
                'message' => $message,
                'message_type' => 'system',
                'offer_data' => json_encode($data),
            ];

            Message::create($messageData);

            $chatRoom->update(['last_message_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Failed to create system message', [
                'chat_room_id' => $chatRoom->id,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getContractRequirements(Contract $contract): array
    {
        return is_array($contract->requirements) ? $contract->requirements : [];
    }

    private function resolveTrackingCode(Contract $contract): ?string
    {
        $nativeTrackingCode = $contract->tracking_code;
        if (is_string($nativeTrackingCode) && trim($nativeTrackingCode) !== '') {
            return trim($nativeTrackingCode);
        }

        $legacyTrackingCode = $this->getContractRequirements($contract)['_tracking_code'] ?? null;
        if (! is_string($legacyTrackingCode)) {
            return null;
        }

        $normalizedTrackingCode = trim($legacyTrackingCode);

        return $normalizedTrackingCode !== '' ? $normalizedTrackingCode : null;
    }

    private function resolveWorkflowStatus(Contract $contract): string
    {
        $nativeStatus = (string) $contract->workflow_status;
        if (in_array($nativeStatus, self::LOGISTICS_WORKFLOW_STATUSES, true)) {
            return $nativeStatus;
        }

        $legacyStatus = $this->getContractRequirements($contract)['_logistics_workflow_status'] ?? null;
        if (is_string($legacyStatus) && in_array($legacyStatus, self::LOGISTICS_WORKFLOW_STATUSES, true)) {
            return $legacyStatus;
        }

        return $nativeStatus;
    }

    private function isWorkflowStatusSchemaError(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $sqlState = strtolower((string) ($exception->errorInfo[0] ?? $exception->getCode()));

        $mentionsWorkflow = str_contains($message, 'workflow_status')
            || str_contains($message, 'contracts_workflow_status')
            || str_contains($message, 'check constraint')
            || str_contains($message, 'constraint')
            || str_contains($message, 'enum');

        $mentionsLogisticsStatus = str_contains($message, 'alignment_preparation')
            || str_contains($message, 'material_sent')
            || str_contains($message, 'product_sent')
            || str_contains($message, 'product_received')
            || str_contains($message, 'production_started');

        if (($mentionsWorkflow && $mentionsLogisticsStatus) || str_contains($message, 'data truncated')) {
            return true;
        }

        // Common SQLSTATEs seen when enum/check constraints reject workflow values
        return in_array($sqlState, ['22p02', '23514', '01000'], true) && $mentionsWorkflow;
    }


    private function sendContractCompletionMessage(Contract $contract, User $brand): void
    {
        try {
            $chatRoom = $contract->offer?->chatRoom;

            if (! $chatRoom) {
                Log::warning('No chat room found for contract completion message', [
                    'contract_id' => $contract->id,
                    'offer_id' => $contract->offer_id,
                ]);

                return;
            }

            $message = Message::create([
                'chat_room_id' => $chatRoom->id,
                'sender_id' => null,
                'message' => 'ðŸŽ‰ O contrato foi finalizado com sucesso! O pagamento jÃ¡ foi liberado para a carteira do criador.',
                'message_type' => 'contract_completion',
                'offer_data' => json_encode([
                    'contract_id' => $contract->id,
                    'requires_review' => false,
                    'review_type' => 'optional',
                    'brand_name' => $brand->name,
                    'creator_name' => $contract->creator->name,
                    'contract_title' => $contract->title,
                    'creator_amount' => $contract->formatted_creator_amount,
                    'completed_at' => $contract->completed_at->toISOString(),
                    'show_review_button_for_creator_only' => false,
                ]),
                'is_system_message' => true,
            ]);

            $chatRoom->update(['last_message_at' => now()]);

            // Dispatch event for system message
            // offer_data is already cast as array in Message model, no json_decode needed
            event(new NewMessage($message, $chatRoom, $message->offer_data));
        } catch (Exception $e) {
            Log::error('Failed to send contract completion message', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function sendActivationMessage($contract): void
    {
        try {
            $chatRoom = $contract->offer->chatRoom;

            if (! $chatRoom) {
                Log::warning('No chat room found for contract', [
                    'contract_id' => $contract->id,
                    'offer_id' => $contract->offer_id,
                ]);

                return;
            }

            $this->createSystemMessage($chatRoom, 'âœ… Contrato ativado com sucesso! O trabalho jÃ¡ pode comeÃ§ar.', [
                'contract_id' => $contract->id,
                'status' => 'active',
                'workflow_status' => 'active',
                'message_type' => 'contract_activated',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send activation message', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request): JsonResponse
    {

        $user = $this->getAuthenticatedUser();
        $status = $request->get('status');
        $workflowStatus = $request->get('workflow_status');

        try {
            $query = $user->isBrand()
                ? $user->brandContracts()
                : $user->creatorContracts();

            if ($status) {
                $query->where('status', $status);
            }

            if ($workflowStatus) {
                $query->where('workflow_status', $workflowStatus);
            }

            $contracts = tap(
                $query->with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url', 'offer', 'payment'])
                    ->orderBy('created_at', 'desc')
                    ->paginate(10),
                function (\Illuminate\Pagination\LengthAwarePaginator $paginator) use ($user) {
                    $paginator->getCollection()->transform(function ($contract) use ($user) {
                        $otherUser = $user->isBrand() ? $contract->creator : $contract->brand;

                        return [
                            'id' => $contract->id,
                            'title' => $contract->title,
                            'description' => $contract->description,
                            'budget' => $contract->formatted_budget,
                            'creator_amount' => $contract->formatted_creator_amount,
                            'platform_fee' => $contract->formatted_platform_fee,
                            'estimated_days' => $contract->estimated_days,
                            'requirements' => $contract->requirements,
                            'status' => $contract->status,
                            'workflow_status' => $this->resolveWorkflowStatus($contract),
                            'tracking_code' => $this->resolveTrackingCode($contract),
                            'started_at' => $contract->started_at?->format('Y-m-d H:i:s'),
                            'expected_completion_at' => $contract->expected_completion_at?->format('Y-m-d H:i:s'),
                            'completed_at' => $contract->completed_at?->format('Y-m-d H:i:s'),
                            'cancelled_at' => $contract->cancelled_at?->format('Y-m-d H:i:s'),
                            'cancellation_reason' => $contract->cancellation_reason,
                            'days_until_completion' => $contract->days_until_completion,
                            'progress_percentage' => $contract->progress_percentage,
                            'is_overdue' => $contract->isOverdue(),
                            'is_near_completion' => $contract->is_near_completion,
                            'can_be_completed' => $contract->canBeCompleted(),
                            'can_be_cancelled' => $contract->canBeCancelled(),
                            'can_be_terminated' => $contract->canBeTerminated(),
                            'can_be_started' => $contract->canBeStarted(),
                            'is_waiting_for_review' => $contract->isWaitingForReview(),
                            'is_payment_available' => $contract->isPaymentAvailable(),
                            'is_payment_withdrawn' => $contract->isPaymentWithdrawn(),
                            'has_brand_review' => $contract->has_brand_review,
                            'has_creator_review' => $contract->has_creator_review,
                            'has_both_reviews' => $contract->has_both_reviews,
                            'creator' => [
                                'id' => $contract->creator->id,
                                'name' => $contract->creator->name,
                                'avatar_url' => $contract->creator->avatar_url,
                            ],
                            'other_user' => [
                                'id' => $otherUser->id,
                                'name' => $otherUser->name,
                                'avatar_url' => $otherUser->avatar_url,
                            ],
                            'payment' => $contract->payment ? [
                                'id' => $contract->payment->id,
                                'status' => $contract->payment->status,
                                'total_amount' => $contract->payment->formatted_total_amount,
                                'creator_amount' => $contract->payment->formatted_creator_amount,
                                'platform_fee' => $contract->payment->formatted_platform_fee,
                                'processed_at' => $contract->payment->processed_at?->format('Y-m-d H:i:s'),
                            ] : null,
                            'review' => ($userReview = $contract->userReview($user->id)->first()) ? [
                                'id' => $userReview->id,
                                'rating' => $userReview->rating,
                                'comment' => $userReview->comment,
                                'created_at' => $userReview->created_at->format('Y-m-d H:i:s'),
                            ] : null,
                            'created_at' => $contract->created_at->format('Y-m-d H:i:s'),
                        ];
                    });
                }
            );

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching contracts', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contracts',
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {

        $user = $this->getAuthenticatedUser();

        try {
            $contract = Contract::with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url', 'offer'])
                ->where(function ($query) use ($user) {
                    $query->where('brand_id', $user->id)
                        ->orWhere('creator_id', $user->id);
                })
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou acesso negado',
                ], 404);
            }

            $otherUser = $user->isBrand() ? $contract->creator : $contract->brand;

            $contractData = [
                'id' => $contract->id,
                'title' => $contract->title,
                'description' => $contract->description,
                'budget' => $contract->formatted_budget,
                'creator_amount' => $contract->formatted_creator_amount,
                'platform_fee' => $contract->formatted_platform_fee,
                'estimated_days' => $contract->estimated_days,
                'requirements' => $contract->requirements,
                'status' => $contract->status,
                'workflow_status' => $this->resolveWorkflowStatus($contract),
                'tracking_code' => $this->resolveTrackingCode($contract),
                'started_at' => $contract->started_at?->format('Y-m-d H:i:s'),
                'expected_completion_at' => $contract->expected_completion_at?->format('Y-m-d H:i:s'),
                'completed_at' => $contract->completed_at?->format('Y-m-d H:i:s'),
                'cancelled_at' => $contract->cancelled_at?->format('Y-m-d H:i:s'),
                'cancellation_reason' => $contract->cancellation_reason,
                'days_until_completion' => $contract->days_until_completion,
                'progress_percentage' => $contract->progress_percentage,
                'is_overdue' => $contract->isOverdue(),
                'is_near_completion' => $contract->is_near_completion,
                'can_be_completed' => $contract->can_be_completed,
                'can_be_cancelled' => $contract->can_be_cancelled,
                'can_be_terminated' => $contract->canBeTerminated(),
                'is_waiting_for_review' => $contract->isWaitingForReview(),
                'is_payment_available' => $contract->isPaymentAvailable(),
                'is_payment_withdrawn' => $contract->isPaymentWithdrawn(),
                'has_brand_review' => $contract->has_brand_review,
                'has_creator_review' => $contract->has_creator_review,
                'has_both_reviews' => $contract->has_both_reviews,
                'can_review' => ! $contract->has_creator_review,
                'creator' => [
                    'id' => $contract->creator->id,
                    'name' => $contract->creator->name,
                    'avatar_url' => $contract->creator->avatar_url,
                ],
                'other_user' => [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar_url' => $otherUser->avatar_url,
                ],
                'payment' => $contract->payment ? [
                    'id' => $contract->payment->id,
                    'status' => $contract->payment->status,
                    'total_amount' => $contract->payment->formatted_total_amount,
                    'creator_amount' => $contract->payment->formatted_creator_amount,
                    'platform_fee' => $contract->payment->formatted_platform_fee,
                    'processed_at' => $contract->payment->processed_at?->format('Y-m-d H:i:s'),
                ] : null,
                'review' => ($userReview = $contract->userReview($user->id)->first()) ? [
                    'id' => $userReview->id,
                    'rating' => $userReview->rating,
                    'comment' => $userReview->comment,
                    'created_at' => $userReview->created_at->format('Y-m-d H:i:s'),
                ] : null,
                'created_at' => $contract->created_at->format('Y-m-d H:i:s'),
            ];

            return response()->json([
                'success' => true,
                'data' => $contractData,
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching contract', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contract',
            ], 500);
        }
    }

    public function getContractsForChatRoom(Request $request, string $roomId): JsonResponse
    {

        $user = $this->getAuthenticatedUser();

        $chatRoom = ChatRoom::where('room_id', $roomId)
            ->where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
            ->first();

        if (! $chatRoom) {
            return response()->json([
                'success' => false,
                'message' => 'Chat room not found or access denied',
            ], 404);
        }

        try {

            $baseQuery = Contract::whereHas('offer', function ($query) use ($chatRoom) {
                $query->where('chat_room_id', $chatRoom->id);
            })
                ->orderBy('created_at', 'desc');

            try {
                $contractModels = (clone $baseQuery)
                    ->with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url', 'offer', 'payment'])
                    ->get();
            } catch (\Throwable $contractsWithPaymentError) {
                Log::warning('Failed to load contracts with payment relation for chat room, retrying without payment', [
                    'user_id' => $user->id,
                    'chat_room_id' => $chatRoom->id,
                    'room_id' => $chatRoom->room_id,
                    'error' => $contractsWithPaymentError->getMessage(),
                    'exception' => get_class($contractsWithPaymentError),
                ]);

                $contractModels = (clone $baseQuery)
                    ->with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url', 'offer'])
                    ->get();
            }

            $contracts = $contractModels
                ->map(function (Contract $contract) use ($user, $chatRoom) {
                    try {
                        $creator = $contract->creator;
                        $brand = $contract->brand;
                        $otherUser = $user->isBrand() ? ($creator ?? $brand) : ($brand ?? $creator);
                        $payment = $contract->relationLoaded('payment') ? $contract->getRelation('payment') : null;

                        if (! $creator || ! $brand) {
                            Log::warning('Contract has missing participant relation when fetching contracts for chat room', [
                                'contract_id' => $contract->id,
                                'chat_room_id' => $chatRoom->id,
                                'room_id' => $chatRoom->room_id,
                                'brand_id' => $contract->brand_id,
                                'creator_id' => $contract->creator_id,
                                'brand_loaded' => (bool) $brand,
                                'creator_loaded' => (bool) $creator,
                            ]);
                        }

                        $review = null;
                        try {
                            $userReview = $contract->userReview($user->id)->first();
                            if ($userReview) {
                                $review = [
                                    'id' => $userReview->id,
                                    'rating' => $userReview->rating,
                                    'comment' => $userReview->comment,
                                    'created_at' => $userReview->created_at?->format('Y-m-d H:i:s'),
                                ];
                            }
                        } catch (\Throwable $reviewError) {
                            Log::warning('Failed to load contract review for chat room payload', [
                                'contract_id' => $contract->id,
                                'user_id' => $user->id,
                                'error' => $reviewError->getMessage(),
                            ]);
                        }

                        $canBeStarted = false;
                        if ($contract->relationLoaded('payment')) {
                            $canBeStarted = $contract->canBeStarted();
                        }

                        return [
                            'id' => $contract->id,
                            'title' => $contract->title,
                            'description' => $contract->description,
                            'budget' => $contract->formatted_budget,
                            'creator_amount' => $contract->formatted_creator_amount,
                            'platform_fee' => $contract->formatted_platform_fee,
                            'estimated_days' => $contract->estimated_days,
                            'requirements' => $contract->requirements,
                            'status' => $contract->status,
                            'workflow_status' => $this->resolveWorkflowStatus($contract),
                            'tracking_code' => $this->resolveTrackingCode($contract),
                            'started_at' => $contract->started_at?->format('Y-m-d H:i:s'),
                            'expected_completion_at' => $contract->expected_completion_at?->format('Y-m-d H:i:s'),
                            'completed_at' => $contract->completed_at?->format('Y-m-d H:i:s'),
                            'cancelled_at' => $contract->cancelled_at?->format('Y-m-d H:i:s'),
                            'cancellation_reason' => $contract->cancellation_reason,
                            'days_until_completion' => $contract->days_until_completion,
                            'progress_percentage' => $contract->progress_percentage,
                            'is_overdue' => $contract->isOverdue(),
                            'is_near_completion' => $contract->is_near_completion,
                            'can_be_completed' => $contract->canBeCompleted(),
                            'can_be_cancelled' => $contract->canBeCancelled(),
                            'can_be_terminated' => $contract->canBeTerminated(),
                            'can_be_started' => $canBeStarted,
                            'has_brand_review' => $contract->has_brand_review,
                            'has_creator_review' => $contract->has_creator_review,
                            'has_both_reviews' => $contract->has_both_reviews,
                            'creator' => [
                                'id' => $creator?->id ?? $contract->creator_id,
                                'name' => $creator?->name ?? 'Usuario indisponivel',
                                'avatar_url' => $creator?->avatar_url,
                            ],
                            'other_user' => [
                                'id' => $otherUser?->id ?? ($user->isBrand() ? $contract->creator_id : $contract->brand_id),
                                'name' => $otherUser?->name ?? 'Usuario indisponivel',
                                'avatar_url' => $otherUser?->avatar_url,
                            ],
                            'payment' => $payment ? [
                                'id' => $payment->id,
                                'status' => $payment->status,
                                'total_amount' => $payment->formatted_total_amount,
                                'creator_amount' => $payment->formatted_creator_amount,
                                'platform_fee' => $payment->formatted_platform_fee,
                                'processed_at' => $payment->processed_at?->format('Y-m-d H:i:s'),
                            ] : null,
                            'review' => $review,
                            'created_at' => $contract->created_at?->format('Y-m-d H:i:s'),
                        ];
                    } catch (\Throwable $contractPayloadError) {
                        Log::error('Failed to serialize contract payload for chat room', [
                            'contract_id' => $contract->id,
                            'chat_room_id' => $chatRoom->id,
                            'room_id' => $chatRoom->room_id,
                            'error' => $contractPayloadError->getMessage(),
                            'exception' => get_class($contractPayloadError),
                        ]);

                        return null;
                    }
                })
                ->filter()
                ->values();

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching contracts for chat room', [
                'user_id' => $user->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contracts',
            ], 500);
        }
    }

    public function activate(int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can activate contracts',
            ], 403);
        }

        try {
            $contract = Contract::where('brand_id', $user->id)
                ->where('status', 'pending')
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou nÃ£o pode ser ativado',
                ], 404);
            }

            if (! $contract->canBeStarted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o pode ser ativado',
                ], 400);
            }

            $contract->update([
                'status' => 'active',
                'workflow_status' => 'active',
                'started_at' => now(),
            ]);

            $this->sendActivationMessage($contract);

            event(new ContractActivated($contract, $contract->offer->chatRoom, $user->id));

            Log::info('Contract activated successfully', [
                'contract_id' => $contract->id,
                'brand_id' => $user->id,
                'creator_id' => $contract->creator_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contrato ativado com sucesso!',
                'data' => [
                    'contract_id' => $contract->id,
                    'status' => $contract->status,
                    'workflow_status' => $contract->workflow_status,
                    'next_step' => 'work_in_progress',
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Error activating contract', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao ativar contrato. Tente novamente.',
            ], 500);
        }
    }

    public function complete(int $id): JsonResponse
    {

        $user = $this->getAuthenticatedUser();

        if (! $user->isBrand()) {
            Log::warning('Non-brand user attempted to complete contract', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'contract_id' => $id,
                'is_brand' => $user->isBrand(),
                'is_creator' => $user->isCreator(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Apenas marcas podem finalizar contratos',
            ], 403);
        }

        try {
            $contract = Contract::where('brand_id', $user->id)
                ->where('status', 'active')
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou nÃ£o pode ser finalizado',
                ], 404);
            }

            if (! $contract->canBeCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o pode ser finalizado',
                ], 400);
            }

            if ($contract->complete()) {

                $this->sendContractCompletionMessage($contract, $user);

                try {
                    event(new ContractCompleted($contract, $contract->offer->chatRoom, $user->id));
                } catch (\Throwable $broadcastException) {
                    Log::error('Failed to broadcast ContractCompleted event', [
                        'contract_id' => $contract->id,
                        'user_id' => $user->id,
                        'error' => $broadcastException->getMessage(),
                    ]);
                }

                Log::info('Campaign completed successfully', [
                    'contract_id' => $contract->id,
                    'brand_id' => $user->id,
                    'creator_id' => $contract->creator_id,
                    'chat_message_sent' => true,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Campanha finalizada com sucesso! O pagamento foi liberado para a carteira do criador.',
                    'data' => [
                        'contract_id' => $contract->id,
                        'status' => $contract->status,
                        'workflow_status' => $contract->workflow_status,
                        'requires_review' => false,
                        'next_step' => 'payment_released',
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao finalizar campanha',
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('Error completing campaign', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao finalizar campanha. Tente novamente.',
            ], 500);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ValidaÃ§Ã£o falhou',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->getAuthenticatedUser();

        try {
            $contract = Contract::where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
                ->where('status', 'active')
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou nÃ£o pode ser cancelado',
                ], 404);
            }

            if (! $contract->canBeCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o pode ser cancelado',
                ], 400);
            }

            if ($contract->cancel($request->reason)) {
                Log::info('Contract cancelled successfully', [
                    'contract_id' => $contract->id,
                    'user_id' => $user->id,
                    'reason' => $request->reason,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Contrato cancelado com sucesso',
                    'data' => [
                        'contract_id' => $contract->id,
                        'status' => $contract->status,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao cancelar contrato',
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('Error cancelling contract', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao cancelar contrato. Tente novamente.',
            ], 500);
        }
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ValidaÃ§Ã£o falhou',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->getAuthenticatedUser();

        if (! $user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas marcas podem terminar contratos',
            ], 403);
        }

        try {
            $contract = Contract::where('brand_id', $user->id)
                ->where('status', 'active')
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou nÃ£o pode ser terminado',
                ], 404);
            }

            if (! $contract->canBeTerminated()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o pode ser terminado',
                ], 400);
            }

            if ($contract->terminate($request->reason)) {

                $chatRoom = $contract->offer?->chatRoom;

                if ($chatRoom) {

                    $this->createOfferChatMessage($chatRoom, 'contract_terminated', [
                        'sender_id' => $user->id,
                        'message' => $request->reason ?
                            'Contrato terminado pela marca. Motivo: '.$request->reason :
                            'Contrato terminado pela marca.',
                        'offer_data' => [
                            'contract_id' => $contract->id,
                            'title' => $contract->title,
                            'description' => $contract->description,
                            'status' => $contract->status,
                            'workflow_status' => $contract->workflow_status,
                            'budget' => $contract->budget,
                            'formatted_budget' => $contract->formatted_budget,
                            'estimated_days' => $contract->estimated_days,
                            'cancelled_at' => $contract->cancelled_at?->format('Y-m-d H:i:s'),
                            'cancellation_reason' => $contract->cancellation_reason,
                            'termination_type' => 'brand_terminated',
                            'sender' => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'avatar_url' => $user->avatar_url,
                            ],
                        ],
                    ]);
                }

                event(new ContractTerminated($contract, $contract->offer->chatRoom, $user->id, $request->reason));

                Log::info('Contract terminated successfully', [
                    'contract_id' => $contract->id,
                    'brand_id' => $user->id,
                    'reason' => $request->reason,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Contrato terminado com sucesso',
                    'data' => [
                        'contract_id' => $contract->id,
                        'status' => $contract->status,
                        'workflow_status' => $contract->workflow_status,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao terminar contrato',
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('Error terminating contract', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao terminar contrato. Tente novamente.',
            ], 500);
        }
    }

    public function dispute(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ValidaÃ§Ã£o falhou',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->getAuthenticatedUser();

        try {
            $contract = Contract::where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
                ->where('status', 'active')
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou nÃ£o pode ser disputado',
                ], 404);
            }

            if ($contract->dispute($request->reason)) {
                Log::info('Contract disputed successfully', [
                    'contract_id' => $contract->id,
                    'user_id' => $user->id,
                    'reason' => $request->reason,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Contrato disputado com sucesso. Nossa equipe revisarÃ¡ o caso.',
                    'data' => [
                        'contract_id' => $contract->id,
                        'status' => $contract->status,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao disputar contrato',
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('Error disputing contract', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao disputar contrato. Tente novamente.',
            ], 500);
        }
    }

    public function updateWorkflowStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workflow_status' => 'required|string|in:alignment_preparation,material_sent,product_sent,product_received',
            'tracking_code' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'ValidaÃ§Ã£o falhou',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->getAuthenticatedUser();

        try {
            $contract = Contract::where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
                ->where('status', 'active')
                ->find($id);

            if (! $contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contrato nÃ£o encontrado ou acesso negado',
                ], 404);
            }

            $newStatus = (string) $request->input('workflow_status');
            $trackingCode = null;
            if ($request->has('tracking_code')) {
                $trackingCode = trim((string) $request->input('tracking_code'));
                if ('' === $trackingCode) {
                    $trackingCode = null;
                }
            }

            $currentTrackingCode = $this->resolveTrackingCode($contract);
            $trackingCodeToPersist = $trackingCode ?: $currentTrackingCode;
            $hasTrackingCodeColumn = Schema::hasColumn('contracts', 'tracking_code');

            // Authorization logic for specific statuses
            if (in_array($newStatus, self::SHIPPING_WORKFLOW_STATUSES, true) && ! $user->isBrand()) {
                return response()->json(['success' => false, 'message' => 'Apenas a marca pode marcar material/produto como enviado'], 403);
            }

            if ('product_received' === $newStatus && ! $user->isCreator()) {
                return response()->json(['success' => false, 'message' => 'Apenas o criador pode confirmar recebimento do produto'], 403);
            }

            if (in_array($newStatus, self::SHIPPING_WORKFLOW_STATUSES, true) && ! $trackingCodeToPersist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe o codigo de rastreio para marcar o envio do produto.',
                ], 422);
            }

            $requirements = $this->getContractRequirements($contract);
            $requirements['_logistics_workflow_status'] = $newStatus;
            if ($trackingCodeToPersist) {
                $requirements['_tracking_code'] = $trackingCodeToPersist;
            }

            $updateData = [
                'requirements' => $requirements,
            ];

            if ($hasTrackingCodeColumn && ($trackingCodeToPersist || (null !== $trackingCode && $user->isBrand()))) {
                $updateData['tracking_code'] = $trackingCodeToPersist;
            }

            try {
                $contract->update($updateData);
            } catch (\Throwable $primaryPersistenceError) {
                Log::warning('Primary logistics persistence failed, attempting query builder fallback', [
                    'contract_id' => $contract->id,
                    'requested_workflow_status' => $newStatus,
                    'error' => $primaryPersistenceError->getMessage(),
                    'exception' => get_class($primaryPersistenceError),
                ]);

                $fallbackData = [
                    'requirements' => json_encode($requirements, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'updated_at' => now(),
                ];

                if ($hasTrackingCodeColumn && array_key_exists('tracking_code', $updateData)) {
                    $fallbackData['tracking_code'] = $updateData['tracking_code'];
                }

                try {
                    Contract::query()->whereKey($contract->id)->update($fallbackData);
                } catch (\Throwable $fallbackError) {
                    Log::error('Logistics fallback persistence failed', [
                        'contract_id' => $contract->id,
                        'requested_workflow_status' => $newStatus,
                        'error' => $fallbackError->getMessage(),
                        'exception' => get_class($fallbackError),
                    ]);
                    throw $fallbackError;
                }
            }

            // Best-effort sync with native workflow_status for databases that already support logistics statuses.
            try {
                Contract::query()->whereKey($contract->id)->update(['workflow_status' => $newStatus]);
            } catch (QueryException $queryException) {
                if ($this->isWorkflowStatusSchemaError($queryException)) {
                    Log::info('Skipped native workflow_status sync due legacy schema constraints', [
                        'contract_id' => $contract->id,
                        'requested_workflow_status' => $newStatus,
                        'sqlstate' => $queryException->errorInfo[0] ?? $queryException->getCode(),
                    ]);
                } else {
                    Log::warning('Unexpected database error while syncing native workflow_status', [
                        'contract_id' => $contract->id,
                        'requested_workflow_status' => $newStatus,
                        'error' => $queryException->getMessage(),
                    ]);
                }
            } catch (\Throwable $workflowSyncError) {
                Log::warning('Unexpected runtime error while syncing native workflow_status', [
                    'contract_id' => $contract->id,
                    'requested_workflow_status' => $newStatus,
                    'error' => $workflowSyncError->getMessage(),
                    'exception' => get_class($workflowSyncError),
                ]);
            }

            $contract->refresh();

            // Notify via chat/system message
            $chatRoom = $contract->offer?->chatRoom;
            if ($chatRoom) {
                $messageText = match ($newStatus) {
                    'material_sent' => 'ðŸ“¦ Material enviado pela marca. Aguardando confirmaÃ§Ã£o de recebimento.',
                    'product_sent' => 'ðŸ“¦ Produto enviado pela marca. Aguardando confirmaÃ§Ã£o de recebimento.',
                    'product_received' => 'âœ… Produto/Material recebido pelo criador. O prazo de produÃ§Ã£o comeÃ§ou!',
                    default => 'Status de logÃ­stica atualizado: '.$newStatus,
                };

                $this->createSystemMessage($chatRoom, $messageText, [
                    'contract_id' => $contract->id,
                    'workflow_status' => $this->resolveWorkflowStatus($contract),
                    'tracking_code' => $this->resolveTrackingCode($contract),
                    'message_type' => 'logistics_update',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Status atualizado com sucesso',
                'data' => [
                    'contract_id' => $contract->id,
                    'workflow_status' => $this->resolveWorkflowStatus($contract),
                    'tracking_code' => $this->resolveTrackingCode($contract),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error updating contract workflow status', [
                'user_id' => $user->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'requested_workflow_status' => $request->input('workflow_status'),
                'has_tracking_code' => $request->has('tracking_code'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao atualizar status',
            ], 500);
        }
    }
}

