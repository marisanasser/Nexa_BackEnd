<?php

namespace App\Http\Controllers\Chat;

use App\Events\Chat\MessagesRead;
use App\Events\Chat\NewMessage;
use App\Events\Chat\UserTyping;
use App\Helpers\FileUploadHelper;
use App\Http\Controllers\Base\Controller;
use App\Models\Campaign\CampaignApplication;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\Contract\Contract;
use App\Models\Contract\Offer;
use App\Models\User\UserOnlineStatus;
use App\Traits\OfferChatMessageTrait;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    use OfferChatMessageTrait;

    public function getChatRooms(Request $request): JsonResponse
    {

        $user = $this->getAuthenticatedUser();

        Log::info('CheckChatRooms Debug', [
            'user_id' => $user->id,
        ]);

        $chatRooms = collect();
        $perPage = (int) $request->query('per_page', '100');
        // Por padrão, não incluir chats arquivados
        $includeArchived = filter_var($request->query('include_archived', 'false'), FILTER_VALIDATE_BOOLEAN);

        if ($user->isBrand()) {
            $query = ChatRoom::where('brand_id', $user->id);
            
            // Filtrar chats arquivados se necessário
            if (!$includeArchived) {
                $query->notArchived();
            }
            
            $chatRooms = $query->with([
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
            $query = ChatRoom::where('creator_id', $user->id);
            
            // Filtrar chats arquivados se necessário
            if (!$includeArchived) {
                $query->notArchived();
            }
            
            $chatRooms = $query->with([
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
            $query = ChatRoom::query();
            
            // Filtrar chats arquivados se necessário
            if (!$includeArchived) {
                $query->notArchived();
            }

            $chatRooms = $query->with([
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
                'chat_status' => $room->chat_status ?? 'active',
                'can_send_messages' => $room->canSendMessages(),
                'archived_at' => $room->archived_at?->toISOString(),
                'other_user' => [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar' => $otherUser->avatar,
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
        $user = $this->getAuthenticatedUser();
        $room = $this->findChatRoom($roomId, $user);

        if (! $room) {
            return response()->json(['success' => false, 'message' => 'Chat room not found'], 404);
        }

        $this->ensureInitialOfferAndMarkRead($room, $user);

        $perPage = (int) $request->query('per_page', 50);
        $page = (int) $request->query('page', 1);

        $messagesQuery = $room->messages()
            ->with('sender')
            ->orderBy('created_at', 'desc');

        if ($user->isBrand()) {
            $messagesQuery->where('message_type', '!=', 'system_creator');
        } elseif ($user->isCreator() || $user->isStudent()) {
            $messagesQuery->where('message_type', '!=', 'system_brand');
        }

        $messagesPaginator = $messagesQuery->paginate($perPage, ['*'], 'page', $page);

        $messagesCollection = collect($messagesPaginator->items())->sortBy('created_at')->values();

        $formattedMessages = $messagesCollection->map(function ($message) use ($user) {
            return $this->formatMessageData($message, $user);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'room' => [
                    'id' => $room->id,
                    'room_id' => $room->room_id,
                    'campaign_id' => $room->campaign_id,
                    'campaign_title' => $room->campaign?->title,
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

    private function ensureInitialOfferAndMarkRead(ChatRoom $room, $user): void
    {
        if ($room->campaign_id && ! $room->messages()->exists()) {
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
        }
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'room_id' => 'required|string',
            'message' => 'required_without:file|string|max:1000',
            'file' => 'nullable|file|max:10240',
        ]);

        $user = $this->getAuthenticatedUser();
        $room = $this->findChatRoom($request->room_id, $user);

        if (! $room) {
            return response()->json(['success' => false, 'message' => 'Chat room not found'], 404);
        }

        // Verifica se o chat pode receber mensagens (não arquivado)
        if (! $room->canSendMessages()) {
            return response()->json([
                'success' => false, 
                'message' => 'Este chat foi arquivado e não aceita novas mensagens.',
                'chat_status' => $room->chat_status,
            ], 403);
        }

        $messageData = $this->prepareMessageData($request, $room, $user);

        try {
            $message = Message::create($messageData);
            $room->update(['last_message_at' => now()]);
            $message->load('sender');

            $responseData = $this->formatMessageData($message, $user, true);
            $offerData = $message->offer_data;

            try {
                event(new NewMessage($message, $room, $offerData));
            } catch (\Throwable $e) {
                Log::error('Failed to broadcast NewMessage event', ['error' => $e->getMessage()]);
            }

            return response()->json(['success' => true, 'data' => $responseData]);
        } catch (Exception $e) {
            Log::error('Error creating message', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Failed to send message.'], 500);
        }
    }

    private function prepareMessageData(Request $request, ChatRoom $room, $user): array
    {
        $messageData = [
            'chat_room_id' => $room->id,
            'sender_id' => $user->id,
            'message' => $request->message ?? '',
            'message_type' => 'text',
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = 'chat-files/'.$user->id;
            $url = FileUploadHelper::upload($file, $path);

            if (!$url) {
                throw new Exception('Failed to upload chat file');
            }

            // FileUploadHelper::upload returns the relative path which we store in DB.
            // FileUploadHelper::resolveUrl handles both local and GCS paths.
            $filePath = $url;

            if (empty($messageData['message'])) {
                $messageData['message'] = $file->getClientOriginalName();
            }

            $messageData['message_type'] = $this->getFileType($file->getMimeType());
            $messageData['file_path'] = $filePath;
            $messageData['file_name'] = $file->getClientOriginalName();
            $messageData['file_size'] = $file->getSize();
            $messageData['file_type'] = $file->getMimeType();
        }

        // Sanitize message content to prevent platform bypass
        if (!empty($messageData['message']) && $messageData['message_type'] === 'text') {
            $messageData['message'] = $this->sanitizeMessageContent($messageData['message']);
        }

        return $messageData;
    }

    /**
     * Sanitize message content to remove sensitive contacts and prevent platform bypass.
     */
    private function sanitizeMessageContent(string $content): string
    {
        // 1. Mask Emails
        $content = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL REMOVIDO - USE O CHAT]', $content);

        // 2. Mask URLs (Basic detection)
        // Note: We allow internal links if needed, but for now blocking all external is safer for anti-leakage.
        $content = preg_replace('/\b((https?|ftp):\/\/|www\.)[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i', '[LINK REMOVIDO - MANTENHA A NEGOCIAÇÃO AQUI]', $content);

        // 3. Mask Phones/Keywords
        // It's hard to regex phones perfectly without false positives (like budgets/dates).
        // Instead, we target intent keywords combined with simplistic number patterns or just the keywords.
        $keywords = ['whatsapp', 'telegram', 'zap', 'signal', 'meu numero', 'meu telefone', 'contato por fora', 'pix direto'];
        
        foreach ($keywords as $keyword) {
             $content = preg_replace('/\b' . preg_quote($keyword, '/') . '\b/i', '***', $content);
        }
        
        // Attempt to catch phone numbers: (XX) 9XXXX-XXXX or similar
        // This regex looks for 10-11 digits with optional separators
        $content = preg_replace('/(?:\(?\d{2}\)?\s*)?(?:9\d{4}[-\s]?\d{4}|\d{4}[-\s]?\d{4})\b/', '[TELEFONE REMOVIDO]', $content);

        return $content;
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

        $user = $this->getAuthenticatedUser();

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

        $user = $this->getAuthenticatedUser();

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

        $user = $this->getAuthenticatedUser();

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
                    "**🔒 Regras de Segurança (ANTI-FRAUDE):**\n\n".
                    "✅ **Comunicação:** Exclusivamente pelo chat da NEXA. O uso de WhatsApp, Telegram ou e-mail pessoal é **estritamente proibido** e pode levar ao banimento.\n".
                    "❌ **Pagamentos por fora:** Qualquer tentativa de pagamento direto é insegura e viola nossos Termos de Uso.\n".
                    "⚠️ **Garantia:** Apenas pagamentos feitos via NEXA são garantidos e reembolsáveis.\n".
                    "🚫 **Dados:** O envio de telefone/email será bloqueado automaticamente pelo sistema.\n\n".
                    'A NEXA está aqui para facilitar conexões seguras e profissionais! 💼✨';

                Message::create([
                    'chat_room_id' => $room->id,
                    'sender_id' => $user->id,
                    'message' => $brandMessage,
                    'message_type' => 'system_brand',
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
                    "**🔒 Regras Importantes (ANTI-FRAUDE):**\n\n".
                    "✅ **Chat:** Comunicação exclusivamente pela NEXA. Não leve a conversa para WhatsApp/Email.\n".
                    "❌ **Pagamento Seguro:** Nunca aceite pagamentos por fora. A NEXA garante seu recebimento apenas dentro da plataforma.\n".
                    "⚠️ **Dados:** O compartilhamento de contato pessoal é proibido e monitorado.\n".
                    "🚫 **Risco:** Negociações externas não têm suporte da NEXA em caso de calote.\n\n".
                    'Estamos aqui para garantir a melhor experiência! Boa campanha! 💼💡';

                Message::create([
                    'chat_room_id' => $room->id,
                    'sender_id' => $user->id,
                    'message' => $creatorMessage,
                    'message_type' => 'system_creator',
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

        } catch (Exception $e) {
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

        $user = $this->getAuthenticatedUser();

        $onlineStatus = UserOnlineStatus::firstOrCreate(['user_id' => $user->id]);
        $onlineStatus->setTypingInRoom($request->room_id, $request->is_typing);

        // If user is typing, ensure they are marked as online
        if ($request->is_typing && ! $onlineStatus->is_online) {
            $onlineStatus->updateOnlineStatus(true);
        }

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

    /**
     * Helper to find a chat room by ID and user.
     */
    private function findChatRoom(string $roomId, $user): ?ChatRoom
    {
        if ($user->isAdmin()) {
            return ChatRoom::where('room_id', $roomId)->first();
        }

        return ChatRoom::where('room_id', $roomId)
            ->where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
            ->first();
    }

    /**
     * Helper to format message data for API responses.
     */
    private function formatMessageData($message, $user, bool $forceIsSender = false): array
    {
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
            'sender_avatar' => $message->sender ? $message->sender->avatar : null,
            'is_sender' => $forceIsSender ?: ($message->sender_id === $user->id),
            'is_read' => $message->is_read,
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at->toISOString(),
        ];

        if (($message->message_type === 'offer' || $message->message_type === 'contract_completion') && $message->offer_data) {
            $messageData['offer_data'] = $this->enrichOfferData($message->offer_data);
        } else {
            $messageData['offer_data'] = null;
        }

        return $messageData;
    }

    private function enrichOfferData($offerData): ?array
    {
        if (! $offerData) {
            return null;
        }

        if (is_string($offerData)) {
            $decoded = json_decode($offerData, true);
            if (is_array($decoded)) {
                $offerData = $decoded;
            } else {
                return null;
            }
        }

        if (! is_array($offerData)) {
            return null;
        }

        if (isset($offerData['offer_id'])) {
            $currentOffer = Offer::find($offerData['offer_id']);
            if ($currentOffer) {
                $offerData['status'] = $currentOffer->status;
                $offerData['accepted_at'] = $currentOffer->accepted_at?->format('Y-m-d H:i:s');
                $offerData['rejected_at'] = $currentOffer->rejected_at?->format('Y-m-d H:i:s');
                $offerData['rejection_reason'] = $currentOffer->rejection_reason;

                if ($currentOffer->status === 'accepted') {
                    $contract = Contract::where('offer_id', $currentOffer->id)->first();
                    if ($contract) {
                        $offerData['contract_id'] = $contract->id;
                        $offerData['contract_status'] = $contract->status;
                        $offerData['can_be_completed'] = $contract->canBeCompleted();
                    }
                }
            }
        } elseif (isset($offerData['contract_id'])) {
            $contract = Contract::find($offerData['contract_id']);
            if ($contract) {
                $offerData['contract_status'] = $contract->status;
                $offerData['can_be_completed'] = $contract->canBeCompleted();
            }
        }

        return $offerData;
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

            $existingOffer = Offer::where('campaign_id', $campaign->id)
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

            $offer = Offer::create([
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
                        'avatar_url' => $chatRoom->brand->avatar,
                    ],
                ],
            ]);

            Log::info('Initial offer sent automatically', [
                'chat_room_id' => $chatRoom->id,
                'offer_id' => $offer->id,
                'is_barter' => $isBarter,
                'campaign_id' => $campaign->id,
            ]);

        } catch (Exception $e) {
            Log::error('Error sending initial offer automatically', [
                'error' => $e->getMessage(),
                'chat_room_id' => $chatRoom->id,
                'campaign_id' => $chatRoom->campaign_id,
            ]);
        }
    }
}
