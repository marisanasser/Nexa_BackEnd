<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use App\Models\DirectChatRoom;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ConnectionController extends Controller
{
    public function sendConnectionRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required|integer|exists:users,id',
            'message' => 'nullable|string|max:500',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
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
        $receiver = User::find($request->receiver_id);

        if ($user->id === $receiver->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot send a connection request to yourself',
            ], 400);
        }

        if (! $user->isBrand() || ! $receiver->isCreator()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can send connection requests to creators',
            ], 403);
        }

        $existingRequest = ConnectionRequest::where('sender_id', $user->id)
            ->where('receiver_id', $receiver->id)
            ->first();

        if ($existingRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Connection request already exists',
            ], 400);
        }

        $connectionRequest = ConnectionRequest::create([
            'sender_id' => $user->id,
            'receiver_id' => $receiver->id,
            'message' => $request->message,
            'campaign_id' => $request->campaign_id,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Connection request sent successfully',
            'data' => $connectionRequest->load(['sender', 'receiver', 'campaign']),
        ], 201);
    }

    public function acceptConnectionRequest(Request $request, int $requestId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $connectionRequest = ConnectionRequest::findOrFail($requestId);

        if (! $connectionRequest->canBeAcceptedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot accept this connection request',
            ], 403);
        }

        $connectionRequest->accept();

        $directChatRoom = DirectChatRoom::findOrCreateRoom(
            $connectionRequest->sender_id,
            $connectionRequest->receiver_id,
            $connectionRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Connection request accepted',
            'data' => [
                'connection_request' => $connectionRequest->load(['sender', 'receiver']),
                'chat_room' => $directChatRoom,
            ],
        ]);
    }

    public function rejectConnectionRequest(Request $request, int $requestId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $connectionRequest = ConnectionRequest::findOrFail($requestId);

        if (! $connectionRequest->canBeRejectedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot reject this connection request',
            ], 403);
        }

        $connectionRequest->reject();

        return response()->json([
            'success' => true,
            'message' => 'Connection request rejected',
        ]);
    }

    public function cancelConnectionRequest(Request $request, int $requestId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $connectionRequest = ConnectionRequest::findOrFail($requestId);

        if (! $connectionRequest->canBeCancelledBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot cancel this connection request',
            ], 403);
        }

        $connectionRequest->cancel();

        return response()->json([
            'success' => true,
            'message' => 'Connection request cancelled',
        ]);
    }

    public function getConnectionRequests(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $type = $request->get('type', 'received');

        $query = ConnectionRequest::with(['sender', 'receiver', 'campaign']);

        if ($type === 'sent') {
            $query->bySender($user->id);
        } else {
            $query->byReceiver($user->id);
        }

        if ($request->has('status')) {
            $status = $request->get('status');
            if (in_array($status, ['pending', 'accepted', 'rejected', 'cancelled'])) {
                $query->where('status', $status);
            }
        }

        $connectionRequests = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $connectionRequests,
        ]);
    }

    public function getDirectChatRooms(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $directChatRooms = [];

        if ($user->isBrand()) {
            $directChatRooms = DirectChatRoom::where('brand_id', $user->id)
                ->with(['creator', 'lastMessage.sender'])
                ->orderBy('created_at', 'desc')
                ->orderBy('last_message_at', 'desc')
                ->get();
        } elseif ($user->isCreator()) {
            $directChatRooms = DirectChatRoom::where('creator_id', $user->id)
                ->with(['brand', 'lastMessage.sender'])
                ->orderBy('created_at', 'desc')
                ->orderBy('last_message_at', 'desc')
                ->get();
        }

        $formattedRooms = $directChatRooms->map(function ($room) use ($user) {
            $otherUser = $room->getOtherUser($user->id);
            $lastMessage = $room->lastMessage->first();

            return [
                'id' => $room->id,
                'room_id' => $room->room_id,
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
                'unread_count' => $room->messages()
                    ->where('sender_id', '!=', $user->id)
                    ->where('is_read', false)
                    ->count(),
                'last_message_at' => $room->last_message_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedRooms,
        ]);
    }

    public function getDirectMessages(Request $request, string $roomId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $room = DirectChatRoom::where('room_id', $roomId)
            ->where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
            ->first();

        if (! $room) {
            return response()->json([
                'success' => false,
                'message' => 'Direct chat room not found',
            ], 404);
        }

        $room->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        $messages = $room->messages()
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) use ($user) {
                return [
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
                    'sender_name' => $message->sender->name,
                    'sender_avatar' => $message->sender->avatar_url,
                    'is_sender' => $message->sender_id === $user->id,
                    'is_read' => $message->is_read,
                    'read_at' => $message->read_at?->toISOString(),
                    'created_at' => $message->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'room' => [
                    'id' => $room->id,
                    'room_id' => $room->room_id,
                    'other_user' => $room->getOtherUser($user->id),
                ],
                'messages' => $messages,
            ],
        ]);
    }

    public function sendDirectMessage(Request $request): JsonResponse
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

        $room = DirectChatRoom::where('room_id', $request->room_id)
            ->where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
            ->first();

        if (! $room) {
            return response()->json([
                'success' => false,
                'message' => 'Direct chat room not found',
            ], 404);
        }

        $messageData = [
            'direct_chat_room_id' => $room->id,
            'sender_id' => $user->id,
            'message' => $request->message ?? '',
            'message_type' => 'text',
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = time().'_'.$file->getClientOriginalName();
            $filePath = $file->storeAs('direct-chat-files/' . $user->id, $fileName, config('filesystems.default'));

            if (empty($messageData['message'])) {
                $messageData['message'] = $file->getClientOriginalName();
            }

            $messageData['message_type'] = $this->getFileType($file->getMimeType());
            $messageData['file_path'] = $filePath;
            $messageData['file_name'] = $file->getClientOriginalName();
            $messageData['file_size'] = $file->getSize();
            $messageData['file_type'] = $file->getMimeType();
        }

        $message = DirectMessage::create($messageData);

        $room->update(['last_message_at' => now()]);

        $message->load('sender');

        return response()->json([
            'success' => true,
            'data' => [
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
                'sender_name' => $message->sender->name,
                'sender_avatar' => $message->sender->avatar_url,
                'is_sender' => true,
                'is_read' => false,
                'created_at' => $message->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function searchCreators(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can search for creators',
            ], 403);
        }

        $query = User::where('role', 'creator');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('state')) {
            $query->where('state', $request->get('state'));
        }

        if ($request->has('verified')) {
            $query->where('student_verified', $request->get('verified'));
        }

        $creators = $query->select(['id', 'name', 'email', 'avatar_url', 'bio', 'state', 'student_verified'])
            ->orderBy('name')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $creators,
        ]);
    }

    private function getFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return 'file';
    }
}
