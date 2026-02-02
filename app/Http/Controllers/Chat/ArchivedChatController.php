<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Base\Controller;
use App\Models\Chat\ChatRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para gerenciar chats arquivados e relatórios de campanhas.
 */
class ArchivedChatController extends Controller
{
    /**
     * Lista todos os chats arquivados do usuário autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $limit = (int) $request->query('limit', '20');

        $role = 'any';
        if ($user->isBrand()) {
            $role = 'brand';
        } elseif ($user->isCreator() || $user->isStudent()) {
            $role = 'creator';
        }

        $archivedRooms = ChatRoom::getArchivedRooms($user->id, $role, $limit);

        $formattedRooms = $archivedRooms->map(function ($room) use ($user) {
            $otherUser = $user->isBrand() ? $room->creator : $room->brand;

            return [
                'id' => $room->id,
                'room_id' => $room->room_id,
                'campaign_id' => $room->campaign_id,
                'campaign_title' => $room->campaign?->title ?? 'Campaign Not Found',
                'other_user' => [
                    'id' => $otherUser?->id,
                    'name' => $otherUser?->name,
                    'avatar' => $otherUser?->avatar_url,
                ],
                'archived_at' => $room->archived_at?->toISOString(),
                'closure_reason' => $room->closure_reason,
                'summary' => [
                    'total_messages' => $room->campaign_summary['communication']['total_messages'] ?? 0,
                    'total_paid' => $room->campaign_summary['financial']['total_paid_to_creator'] ?? 0,
                    'duration_days' => $room->campaign_summary['duration_days'] ?? 0,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedRooms,
        ]);
    }

    /**
     * Obtém o relatório completo de uma campanha arquivada.
     */
    public function show(Request $request, string $roomId): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $chatRoom = ChatRoom::where('room_id', $roomId)
            ->archived()
            ->first();

        if (!$chatRoom) {
            return response()->json([
                'success' => false,
                'message' => 'Archived chat not found',
            ], 404);
        }

        // Verifica se o usuário tem permissão para ver este chat
        if (!$user->isAdmin() && 
            $chatRoom->brand_id !== $user->id && 
            $chatRoom->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this chat',
            ], 403);
        }

        $report = $chatRoom->getCampaignReport();

        return response()->json([
            'success' => true,
            'data' => [
                'room_id' => $chatRoom->room_id,
                'archived_at' => $chatRoom->archived_at?->toISOString(),
                'closure_reason' => $chatRoom->closure_reason,
                'report' => $report,
            ],
        ]);
    }

    /**
     * Obtém as mensagens de um chat arquivado.
     */
    public function messages(Request $request, string $roomId): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $page = (int) $request->query('page', '1');
        $perPage = (int) $request->query('per_page', '50');

        $chatRoom = ChatRoom::where('room_id', $roomId)
            ->archived()
            ->with(['messages.sender'])
            ->first();

        if (!$chatRoom) {
            return response()->json([
                'success' => false,
                'message' => 'Archived chat not found',
            ], 404);
        }

        // Verifica permissão
        if (!$user->isAdmin() && 
            $chatRoom->brand_id !== $user->id && 
            $chatRoom->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this chat',
            ], 403);
        }

        $messages = $chatRoom->messages()
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        $formattedMessages = collect($messages->items())->map(function ($message) use ($user) {
            return [
                'id' => $message->id,
                'sender' => [
                    'id' => $message->sender_id,
                    'name' => $message->sender?->name,
                    'avatar' => $message->sender?->avatar_url,
                ],
                'message' => $message->message,
                'message_type' => $message->message_type,
                'is_sender' => $message->sender_id === $user->id,
                'created_at' => $message->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedMessages,
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * Exporta o relatório de uma campanha em formato JSON.
     */
    public function export(Request $request, string $roomId): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $chatRoom = ChatRoom::where('room_id', $roomId)
            ->archived()
            ->with(['messages.sender', 'offers.contract', 'campaign', 'brand', 'creator'])
            ->first();

        if (!$chatRoom) {
            return response()->json([
                'success' => false,
                'message' => 'Archived chat not found',
            ], 404);
        }

        // Verifica permissão
        if (!$user->isAdmin() && 
            $chatRoom->brand_id !== $user->id && 
            $chatRoom->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to export this chat',
            ], 403);
        }

        $report = $chatRoom->getCampaignReport();

        // Adiciona mensagens completas ao export
        $messages = $chatRoom->messages->map(function ($message) {
            return [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender?->name,
                'message' => $message->message,
                'message_type' => $message->message_type,
                'created_at' => $message->created_at?->toISOString(),
            ];
        });

        // Adiciona detalhes das ofertas
        $offers = $chatRoom->offers->map(function ($offer) {
            return [
                'id' => $offer->id,
                'title' => $offer->title,
                'budget' => $offer->budget,
                'status' => $offer->status,
                'created_at' => $offer->created_at?->toISOString(),
                'contract' => $offer->contract ? [
                    'id' => $offer->contract->id,
                    'status' => $offer->contract->status,
                    'workflow_status' => $offer->contract->workflow_status,
                    'creator_amount' => $offer->contract->creator_amount,
                    'completed_at' => $offer->contract->completed_at?->toISOString(),
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'export_date' => now()->toISOString(),
                'room_id' => $chatRoom->room_id,
                'report' => $report,
                'messages' => $messages,
                'offers' => $offers,
            ],
        ]);
    }
}
