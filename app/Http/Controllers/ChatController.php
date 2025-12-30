<?php

namespace App\Http\Controllers;

use App\Events\MessagesRead;
use App\Events\NewMessage;
use App\Events\UserTyping;
use App\Models\CampaignApplication;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\UserOnlineStatus;
use App\Traits\OfferChatMessageTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    use OfferChatMessageTrait;

    public function getChatRooms(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        \Illuminate\Support\Facades\Log::info('CheckChatRooms Debug', [
            'user_id' => $user->id,
        ]);


        $chatRooms = collect();
        $perPage = (int) $request->query('per_page', 100);

        if ($user->isBrand()) {
            $chatRooms = ChatRoom::where('brand_id', $user->id)
                ->with([
                    'creator.onlineStatus',
                    'campaign',
                    'lastMessage.sender',
                ])
                ->withCount([
                    'messages as unread_messages_count' => function ($query) use ($user) {
                        $query->where('sender_id', '!=', $user->id)
                            ->where('is_read', false);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->orderBy('last_message_at', 'desc')
                ->limit($perPage)
                ->get();

            // Log::info('Found chat rooms for brand', [ ... ]);
        } elseif ($user->isCreator() || $user->isStudent()) {
            $chatRooms = ChatRoom::where('creator_id', $user->id)
                ->with([
                    'brand.onlineStatus',
                    'campaign',
                    'lastMessage.sender',
                ])
                ->withCount([
                    'messages as unread_messages_count' => function ($query) use ($user) {
                        $query->where('sender_id', '!=', $user->id)
                            ->where('is_read', false);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->orderBy('last_message_at', 'desc')
                ->limit($perPage)
                ->get();

            // Log::info('Found chat rooms for creator/student', [ ... ]);
        } elseif ($user->isAdmin()) {

            $chatRooms = ChatRoom::with([
                'creator.onlineStatus',
                'brand.onlineStatus',
                'campaign',
                'lastMessage.sender',
            ])
                ->withCount([
                    'messages as unread_messages_count' => function ($query) use ($user) {
                        $query->where('sender_id', '!=', $user->id)
                            ->where('is_read', false);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->orderBy('last_message_at', 'desc')
                ->limit($perPage)
                ->get();

            // Log::info('Found chat rooms for admin', [ ... ]);
        }

        $formattedRooms = $chatRooms->map(function ($room) use ($user) {
            $otherUser = $user->isBrand() ? $room->creator : $room->brand;
            
            // Get the actual latest message from the relationship
            $lastMessage = $room->lastMessage->first();

            if ($user->isAdmin()) {
                if ($lastMessage && $lastMessage->sender_id === $room->brand_id) {
                    $otherUser = $room->brand;
                } elseif ($lastMessage && $lastMessage->sender_id === $room->creator_id) {
                    $otherUser = $room->creator;
                } else {
                    $otherUser = $room->creator ?? $room->brand;
                }
            }

            if (! $otherUser) {
                Log::warning('Skipping chat room with null other user', [
                    'room_id' => $room->room_id,
                    'brand_id' => $room->brand_id,
                    'creator_id' => $room->creator_id,
                    'user_id' => $user->id,
                ]);

                return null;
            }

            return [
                'id' => $room->id,
                'room_id' => $room->room_id,
                'campaign_id' => $room->campaign_id,
                'campaign_title' => $room->campaign?->title ?? 'Campaign Not Found',
                'campaign_status' => $room->campaign?->status ?? 'unknown',
                'other_user' => [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar' => $otherUser->avatar_url,
                    'online' => $otherUser->onlineStatus?->is_online ?? false,
                ],
                'last_message' => $lastMessage ? [
                    'id' => $lastMessage->id,
                    'message' => $lastMessage->message,
                    'message_type' => $lastMessage->message_type,
                    'sender_id' => $lastMessage->sender_id,
                    'is_sender' => $lastMessage->sender_id === $user->id,
                    'created_at' => $lastMessage->created_at->toISOString(),
                ] : null,
                'unread_count' => $room->unread_messages_count ?? 0,
                'last_message_at' => $room->last_message_at?->toISOString(),
            ];
        })->filter();

        return response()->json([
            'success' => true,
            'data' => $formattedRooms->values(),
        ]);
    }

    public function getMessages(Request $request, string $roomId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Log::info('Getting messages for room', [ ... ]);

        if ($user->isAdmin()) {

            $room = ChatRoom::where('room_id', $roomId)->first();
        } else {

            $room = ChatRoom::where('room_id', $roomId)
                ->where(function ($query) use ($user) {
                    $query->where('brand_id', $user->id)
                        ->orWhere('creator_id', $user->id);
                })
                ->first();
        }

        if (! $room) {
            // Log::error('Chat room not found for messages', [ ... ]);
            return response()->json([
                'success' => false,
                'message' => 'Chat room not found',
            ], 404);
        }

        // Log::info('Found chat room for messages', [ ... ]);

        if ($user->isBrand() && $room->campaign_id && ! $room->messages()->exists()) {
            $this->sendInitialOfferIfNeeded($room);
        }

        if ($user->isBrand() && $room->campaign_id && $room->messages()->count() === 0) {
            $this->sendInitialOfferIfNeeded($room);
        }

        if (($user->isCreator() || $user->isStudent()) && $room->campaign_id && $room->messages()->count() === 0) {
            $this->sendInitialOfferIfNeeded($room);
        }

        $unreadMessages = $room->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->get();

        if ($unreadMessages->count() > 0) {
            $messageIds = $unreadMessages->pluck('id')->toArray();

            Message::whereIn('id', $messageIds)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            Log::info('Marked messages as read', [
                'message_ids' => $messageIds,
                'count' => count($messageIds),
            ]);
        }

        $perPage = (int) $request->query('per_page', 50);
        $page = (int) $request->query('page', 1);

        $messagesQuery = $room->messages()
            ->with('sender')
            ->orderBy('created_at', 'desc');

        $messagesPaginator = $messagesQuery->paginate($perPage, ['*'], 'page', $page);

        $messagesCollection = collect($messagesPaginator->items())->sortBy('created_at')->values();

        $nullSenderMessages = $messagesCollection->filter(function ($message) {
            return $message->sender === null;
        });

        if ($nullSenderMessages->count() > 0) {
            Log::warning('Found messages with null senders', [
                'room_id' => $roomId,
                'null_sender_message_ids' => $nullSenderMessages->pluck('id')->toArray(),
                'null_sender_user_ids' => $nullSenderMessages->pluck('sender_id')->toArray(),
            ]);
        }

        Log::info('Retrieved messages from database', [
            'room_id' => $roomId,
            'user_id' => $user->id,
            'page' => $messagesPaginator->currentPage(),
            'per_page' => $messagesPaginator->perPage(),
            'total_messages' => $messagesPaginator->total(),
            'page_message_count' => $messagesCollection->count(),
            'message_ids' => $messagesCollection->pluck('id')->toArray(),
            'message_types' => $messagesCollection->pluck('message_type')->countBy()->toArray(),
        ]);

        $formattedMessages = $messagesCollection->map(function ($message) use ($user) {
            $messageData = [
                'id' => $message->id,
                'message' => $message->message,
                'message_type' => $message->message_type,
                'file_path' => $message->file_path,
                'file_name' => $message->file_name,
                'file_size' => $message->file_size,
                'file_type' => $message->file_type,
                'file_url' => $message->file_url,
                'formatted_file_size' => $message->formatted_file_size,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender ? $message->sender->name : 'Unknown User',
                'sender_avatar' => $message->sender ? $message->sender->avatar_url : null,
                'is_sender' => $message->sender_id === $user->id,
                'is_read' => $message->is_read,
                'read_at' => $message->read_at?->toISOString(),
                'created_at' => $message->created_at->toISOString(),
            ];

            if (($message->message_type === 'offer' || $message->message_type === 'contract_completion') && $message->offer_data) {

                $offerData = is_string($message->offer_data) ? json_decode($message->offer_data, true) : $message->offer_data;

                if ($offerData && is_array($offerData)) {

                    if (isset($offerData['offer_id'])) {
                        $currentOffer = \App\Models\Offer::find($offerData['offer_id']);
                        if ($currentOffer) {

                            $offerData['status'] = $currentOffer->status;
                            $offerData['accepted_at'] = $currentOffer->accepted_at?->format('Y-m-d H:i:s');
                            $offerData['rejected_at'] = $currentOffer->rejected_at?->format('Y-m-d H:i:s');
                            $offerData['rejection_reason'] = $currentOffer->rejection_reason;

                            if ($currentOffer->status === 'accepted') {

                                $contract = \App\Models\Contract::where('offer_id', $currentOffer->id)->first();

                                if ($contract) {
                                    $offerData['contract_id'] = $contract->id;
                                    $offerData['contract_status'] = $contract->status;
                                    $offerData['can_be_completed'] = $contract->canBeCompleted();

                                    Log::info('Contract data included in offer message', [
                                        'offer_id' => $currentOffer->id,
                                        'contract_id' => $contract->id,
                                        'contract_status' => $contract->status,
                                        'can_be_completed' => $contract->canBeCompleted(),
                                    ]);
                                } else {
                                    Log::warning('No contract found for accepted offer', [
                                        'offer_id' => $currentOffer->id,
                                        'offer_status' => $currentOffer->status,
                                    ]);
                                }
                            } else {

                                Log::info('No contract data for offer', [
                                    'offer_id' => $currentOffer->id,
                                    'offer_status' => $currentOffer->status,
                                ]);
                            }
                        }
                    }

                    $messageData['offer_data'] = $offerData;

                    if ($message->message_type === 'contract_completion') {
                        Log::info('Contract completion message offer_data included', [
                            'message_id' => $message->id,
                            'message_type' => $message->message_type,
                            'offer_data' => $offerData,
                        ]);
                    }
                } else {

                    $messageData['offer_data'] = null;
                }
            }

            return $messageData;
        });

        Log::info('Returning formatted messages', [
            'room_id' => $roomId,
            'formatted_count' => $formattedMessages->count(),
            'formatted_message_ids' => $formattedMessages->pluck('id')->toArray(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'room' => [
                    'id' => $room->id,
                    'room_id' => $room->room_id,
                    'campaign_id' => $room->campaign_id,
                    'campaign_title' => $room->campaign->title,
                ],
                'messages' => $formattedMessages,
                'meta' => [
                    'current_page' => $messagesPaginator->currentPage(),
                    'last_page' => $messagesPaginator->lastPage(),
                    'per_page' => $messagesPaginator->perPage(),
                    'total' => $messagesPaginator->total(),
                    'has_more' => $messagesPaginator->currentPage() < $messagesPaginator->lastPage(),
                ],
            ],
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required|string',
            'message' => 'required_without:file|string|max:1000',
            'file' => 'nullable|file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        Log::info('Sending message', [
            'room_id' => $request->room_id,
            'user_id' => $user->id,
            'message_length' => strlen($request->message ?? ''),
            'has_file' => $request->hasFile('file'),
        ]);

        if ($user->isAdmin()) {

            $room = ChatRoom::where('room_id', $request->room_id)->first();
        } else {

            $room = ChatRoom::where('room_id', $request->room_id)
                ->where(function ($query) use ($user) {
                    $query->where('brand_id', $user->id)
                        ->orWhere('creator_id', $user->id);
                })
                ->first();
        }

        if (! $room) {
            Log::error('Chat room not found', [
                'room_id' => $request->room_id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Chat room not found',
            ], 404);
        }

        Log::info('Found chat room', [
            'room_id' => $room->room_id,
            'chat_room_id' => $room->id,
            'brand_id' => $room->brand_id,
            'creator_id' => $room->creator_id,
        ]);

        $messageData = [
            'chat_room_id' => $room->id,
            'sender_id' => $user->id,
            'message' => $request->message ?? '',
            'message_type' => 'text',
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = time().'_'.$file->getClientOriginalName();
            
            // Debug: log disk configuration
            $defaultDisk = config('filesystems.default');
            Log::info('File upload attempt', [
                'default_disk' => $defaultDisk,
                'file_name' => $fileName,
                'file_size' => $file->getSize(),
                'file_mime' => $file->getMimeType(),
            ]);
            
            try {
                // Use default disk (configured as 'gcs' in production)
                $filePath = $file->storeAs('chat-files', $fileName);
                Log::info('File stored', ['file_path' => $filePath, 'success' => !empty($filePath)]);
            } catch (\Throwable $e) {
                Log::error('File storage failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $filePath = false;
            }

            if (empty($messageData['message'])) {
                $messageData['message'] = $file->getClientOriginalName();
            }

            $messageData['message_type'] = $this->getFileType($file->getMimeType());
            $messageData['file_path'] = $filePath ?: null;
            $messageData['file_name'] = $file->getClientOriginalName();
            $messageData['file_size'] = $file->getSize();
            $messageData['file_type'] = $file->getMimeType();
        }

        Log::info('Creating message', $messageData);

        try {
            $message = Message::create($messageData);

            Log::info('Message created successfully', [
                'message_id' => $message->id,
                'chat_room_id' => $message->chat_room_id,
                'sender_id' => $message->sender_id,
                'created_at' => $message->created_at,
            ]);

            $room->update(['last_message_at' => now()]);

            $message->load('sender');

            $responseData = [
                'id' => $message->id,
                'message' => $message->message,
                'message_type' => $message->message_type,
                'file_path' => $message->file_path,
                'file_name' => $message->file_name,
                'file_size' => $message->file_size,
                'file_type' => $message->file_type,
                'file_url' => $message->file_url,
                'formatted_file_size' => $message->formatted_file_size,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender ? $message->sender->name : 'Unknown User',
                'sender_avatar' => $message->sender ? $message->sender->avatar_url : null,
                'is_sender' => true,
                'is_read' => false,
                'created_at' => $message->created_at->toISOString(),
            ];

            if (($message->message_type === 'offer' || $message->message_type === 'contract_completion') && $message->offer_data) {

                $offerData = is_string($message->offer_data) ? json_decode($message->offer_data, true) : $message->offer_data;

                if ($offerData && is_array($offerData)) {
                    $responseData['offer_data'] = $offerData;

                    if (isset($offerData['status']) && $offerData['status'] === 'accepted' && isset($offerData['contract_id'])) {
                        $contract = \App\Models\Contract::find($offerData['contract_id']);
                        if ($contract) {
                            $responseData['offer_data']['contract_status'] = $contract->status;
                            $responseData['offer_data']['can_be_completed'] = $contract->canBeCompleted();
                        }
                    }
                } else {

                    $responseData['offer_data'] = null;
                }
            }

            $socketData = [
                'roomId' => $room->room_id,
                'messageId' => $message->id,
                'message' => $message->message,
                'senderId' => $message->sender_id,
                'senderName' => $message->sender ? $message->sender->name : 'Unknown User',
                'senderAvatar' => $message->sender ? $message->sender->avatar_url : null,
                'messageType' => $message->message_type,
                'fileData' => $message->file_path ? [
                    'file_path' => $message->file_path,
                    'file_name' => $message->file_name,
                    'file_size' => $message->file_size,
                    'file_type' => $message->file_type,
                    'file_url' => $message->file_url,
                ] : null,
                'offerData' => $message->offer_data ? json_decode($message->offer_data, true) : null,
                'timestamp' => $message->created_at->toISOString(),
            ];

            Log::info('Emitting socket event for message', $socketData);

            $offerData = $message->offer_data ? json_decode($message->offer_data, true) : null;

            try {
                event(new NewMessage($message, $room, $offerData));
            } catch (\Throwable $broadcastException) {
                Log::error('Failed to broadcast NewMessage event', [
                    'error' => $broadcastException->getMessage(),
                    'trace' => $broadcastException->getTraceAsString(),
                    'message_id' => $message->id,
                    'room_id' => $room->room_id,
                ]);
            }

            Log::info('Message sent successfully', [
                'message_id' => $message->id,
                'response_data' => $responseData,
            ]);

            return response()->json([
                'success' => true,
                'data' => $responseData,
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_data' => $messageData,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message. Please try again.',
            ], 500);
        }
    }

    public function markMessagesAsRead(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required|string',
            'message_ids' => 'required|array',
            'message_ids.*' => 'integer|exists:messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin()) {

            $room = ChatRoom::where('room_id', $request->room_id)->first();
        } else {

            $room = ChatRoom::where('room_id', $request->room_id)
                ->where(function ($query) use ($user) {
                    $query->where('brand_id', $user->id)
                        ->orWhere('creator_id', $user->id);
                })
                ->first();
        }

        if (! $room) {
            return response()->json([
                'success' => false,
                'message' => 'Chat room not found',
            ], 404);
        }

        Message::whereIn('id', $request->message_ids)
            ->where('chat_room_id', $room->id)
            ->where('sender_id', '!=', $user->id)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        // Dispatch event for Reverb/Broadcasting
        event(new MessagesRead($room, $request->message_ids, $user->id));

        return response()->json([
            'success' => true,
            'message' => 'Messages marked as read',
        ]);
    }

    public function createChatRoom(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'campaign_id' => 'required|integer|exists:campaigns,id',
            'creator_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand() && ! $user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands and admins can create chat rooms',
            ], 403);
        }

        $application = CampaignApplication::where('campaign_id', $request->campaign_id)
            ->where('creator_id', $request->creator_id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'No approved application found for this campaign and creator',
            ], 404);
        }

        $room = ChatRoom::findOrCreateRoom(
            $request->campaign_id,
            $user->id,
            $request->creator_id
        );

        if ($room->wasRecentlyCreated) {
            $application->initiateFirstContact();

            Log::info('Application workflow status updated to agreement_in_progress', [
                'application_id' => $application->id,
                'campaign_id' => $request->campaign_id,
                'creator_id' => $request->creator_id,
                'workflow_status' => $application->workflow_status,
            ]);

            $this->sendInitialOfferIfNeeded($room);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'room_id' => $room->room_id,
                'message' => 'Chat room created successfully',
                'workflow_status_updated' => $room->wasRecentlyCreated,
            ],
        ]);
    }

    public function sendGuideMessages(Request $request, string $roomId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        Log::info('sendGuideMessages called', [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'room_id' => $roomId,
        ]);

        $room = ChatRoom::where('room_id', $roomId)
            ->where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
            ->first();

        if (! $room) {
            Log::error('Chat room not found', [
                'user_id' => $user->id,
                'room_id' => $roomId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Chat room not found or access denied',
            ], 404);
        }

        try {

            $existingGuideMessages = Message::where('chat_room_id', $room->id)
                ->where('sender_id', $user->id)
                ->where('message_type', 'system')
                ->where('is_system_message', true)
                ->exists();

            if ($existingGuideMessages) {
                return response()->json([
                    'success' => true,
                    'message' => 'Guide messages already sent',
                ]);
            }

            $otherUser = $user->isBrand() ? $room->creator : $room->brand;

            if ($user->isBrand()) {

                $brandMessage = "🎉 **Parabéns pela parceria iniciada!**\n\n".
                    "Você acaba de conectar com uma criadora talentosa da nossa plataforma. Para garantir o melhor resultado possível, é essencial orientar com detalhamento e clareza.\n\n".
                    "**📋 Próximos Passos Importantes:**\n\n".
                    "• **Saldo:** Insira o valor da campanha na aba \"Saldo\" da plataforma\n".
                    "• **Pagamento:** Libere o pagamento após aprovar o conteúdo final\n".
                    "• **Briefing:** Reforce os pontos principais com a criadora\n".
                    "• **Ajustes:** Até 2 pedidos de ajustes por vídeo são permitidos\n\n".
                    "**🔒 Regras de Segurança:**\n\n".
                    "✅ **Comunicação:** Exclusivamente pelo chat da NEXA\n".
                    "❌ **Dados:** Não compartilhe informações bancárias ou pessoais\n".
                    "⚠️ **Prazos:** Descumprimento pode resultar em advertência\n".
                    "🚫 **Cancelamento:** Produtos devem ser devolvidos se necessário\n\n".
                    'A NEXA está aqui para facilitar conexões seguras e profissionais! 💼✨';

                Message::create([
                    'chat_room_id' => $room->id,
                    'sender_id' => $user->id,
                    'message' => $brandMessage,
                    'message_type' => 'system',
                    'is_system_message' => true,
                ]);
            } else {

                $creatorMessage = "🎉 **Parabéns! Você foi aprovada!**\n\n".
                    "Estamos muito felizes em contar com você! Mostre toda sua criatividade, comprometimento e qualidade para representar bem a marca e nossa plataforma.\n\n".
                    "**📋 Checklist de Sucesso:**\n\n".
                    "• **Endereço:** Confirme seu endereço de envio o quanto antes\n".
                    "• **Roteiro:** Entregue em até 5 dias úteis\n".
                    "• **Briefing:** Siga todas as orientações da marca\n".
                    "• **Aprovação:** Aguarde aprovação do roteiro antes de gravar\n".
                    "• **Conteúdo:** Entregue o vídeo final em até 5 dias úteis após aprovação\n".
                    "• **Qualidade:** Vídeo profissional, até 2 ajustes permitidos\n".
                    "• **Comunicação:** Mantenha retorno rápido no chat\n\n".
                    "**🔒 Regras Importantes:**\n\n".
                    "✅ **Chat:** Comunicação exclusivamente pela NEXA\n".
                    "❌ **Dados:** Não compartilhe informações bancárias ou pessoais\n".
                    "⚠️ **Prazos:** Descumprimento pode resultar em penalizações\n".
                    "🚫 **Cancelamento:** Produtos devem ser devolvidos se necessário\n\n".
                    'Estamos aqui para garantir a melhor experiência! Boa campanha! 💼💡';

                Message::create([
                    'chat_room_id' => $room->id,
                    'sender_id' => $user->id,
                    'message' => $creatorMessage,
                    'message_type' => 'system',
                    'is_system_message' => true,
                ]);
            }

            $statusMessage = "💼 **Detalhes da Campanha**\n\n".
                "**Status:** 🟢 Conectado\n\n".
                "Você está agora conectado e pode começar a conversar!\n".
                'Use o chat para todas as comunicações e siga as diretrizes da plataforma.';

            Message::create([
                'chat_room_id' => $room->id,
                'sender_id' => $user->id,
                'message' => $statusMessage,
                'message_type' => 'system',
                'is_system_message' => true,
            ]);

            Log::info('Guide messages sent successfully', [
                'chat_room_id' => $room->id,
                'user_id' => $user->id,
                'user_role' => $user->role,
                'messages_created' => 3,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Guide messages sent successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send guide messages', [
                'chat_room_id' => $room->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send guide messages',
            ], 500);
        }
    }

    public function updateTypingStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required|string',
            'is_typing' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $onlineStatus = UserOnlineStatus::firstOrCreate(['user_id' => $user->id]);
        $onlineStatus->setTypingInRoom($request->room_id, $request->is_typing);

        // Broadcast the typing event
        try {
            // Removing toOthers() to ensure delivery even if X-Socket-Id header is missing or incorrect
            // The frontend should filter out its own typing events anyway
            broadcast(new UserTyping($request->room_id, $user, $request->is_typing));
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast typing status', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'room_id' => $request->room_id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Typing status updated',
        ]);
    }

    private function getFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return 'file';
    }

    public function sendInitialOfferIfNeeded(ChatRoom $chatRoom): void
    {
        try {
            $campaign = $chatRoom->campaign;
            if (! $campaign) {
                return;
            }

            $existingOffer = \App\Models\Offer::where('campaign_id', $campaign->id)
                ->where('chat_room_id', $chatRoom->id)
                ->first();

            if ($existingOffer) {
                return;
            }

            $isBarter = $campaign->remuneration_type === 'permuta';
            $budget = $isBarter ? 0 : $campaign->budget;

            $estimatedDays = now()->diffInDays($campaign->deadline, false);
            if ($estimatedDays <= 0) {
                $estimatedDays = 30;
            }

            $expiresAt = $campaign->deadline;
            if ($expiresAt->isPast()) {
                $expiresAt = now()->addDays(7);
            }

            $offer = \App\Models\Offer::create([
                'brand_id' => $chatRoom->brand_id,
                'creator_id' => $chatRoom->creator_id,
                'campaign_id' => $campaign->id,
                'chat_room_id' => $chatRoom->id,
                'title' => $isBarter ? 'Oferta de Permuta' : 'Oferta de Projeto',
                'description' => $isBarter ? 'Oferta de permuta baseada na campanha criada' : 'Oferta baseada na campanha criada',
                'budget' => $budget,
                'estimated_days' => $estimatedDays,
                'requirements' => $campaign->requirements ?? [],
                'is_barter' => $isBarter,
                'barter_description' => $isBarter ? 'Permuta baseada na campanha: '.$campaign->title : null,
                'expires_at' => $expiresAt,
            ]);

            $this->createOfferChatMessage($chatRoom, 'offer_created', [
                'sender_id' => $chatRoom->brand_id,
                'message' => $isBarter
                    ? "Oferta de permuta enviada automaticamente (Prazo: {$offer->estimated_days} dias)"
                    : "Oferta enviada automaticamente: {$offer->formatted_budget} (Prazo: {$offer->estimated_days} dias)",
                'offer_data' => [
                    'offer_id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'budget' => $offer->formatted_budget,
                    'formatted_budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'status' => 'pending',
                    'expires_at' => $offer->expires_at->toISOString(),
                    'days_until_expiry' => $offer->days_until_expiry,
                    'is_expiring_soon' => $offer->is_expiring_soon,
                    'created_at' => $offer->created_at->toISOString(),
                    'is_barter' => $isBarter,
                    'barter_description' => $offer->barter_description,
                    'can_be_accepted' => true,
                    'can_be_rejected' => true,
                    'can_be_cancelled' => true,
                    'sender' => [
                        'id' => $chatRoom->brand->id,
                        'name' => $chatRoom->brand->name,
                        'avatar_url' => $chatRoom->brand->avatar_url,
                    ],
                ],
            ]);

            Log::info('Initial offer sent automatically', [
                'chat_room_id' => $chatRoom->id,
                'offer_id' => $offer->id,
                'is_barter' => $isBarter,
                'campaign_id' => $campaign->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending initial offer automatically', [
                'error' => $e->getMessage(),
                'chat_room_id' => $chatRoom->id,
                'campaign_id' => $chatRoom->campaign_id,
            ]);
        }
    }

    // emitSocketEvent is no longer used
    // private function emitSocketEvent(string $event, array $data): void
    // {
    //     try {
    //
    //         \Illuminate\Support\Facades\Http::post('http://localhost:3000/emit', [
    //             'event' => $event,
    //             'data' => $data,
    //         ]);
    //
    //         Log::info("Socket event emitted via HTTP: {$event}", $data);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to emit socket event via HTTP', [
    //             'event' => $event,
    //             'error' => $e->getMessage(),
    //         ]);
    //     }
    // }
}
