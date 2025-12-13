<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $perPage = $request->get('per_page', 20);
        $type = $request->get('type');
        $isRead = $request->get('is_read');

        $query = Notification::where('user_id', $user->id);

        
        if ($type) {
            $query->where('type', $type);
        }

        
        if ($isRead !== null) {
            $query->where('is_read', $isRead);
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    
    public function unreadCount(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $count = NotificationService::getUnreadCount($user->id);

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }

    
    public function markAsRead(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $success = NotificationService::markAsRead($id, $user->id);

        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Notification not found or already read',
        ], 404);
    }

    
    public function markAllAsRead(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $count = NotificationService::markAllAsRead($user->id);

        return response()->json([
            'success' => true,
            'message' => "{$count} notifications marked as read",
            'count' => $count,
        ]);
    }

    
    public function destroy(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
        ]);
    }

    
    public function statistics(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        
        $total = Notification::where('user_id', $user->id)->count();
        $unread = Notification::where('user_id', $user->id)->where('is_read', false)->count();
        $read = $total - $unread;
        
        $byType = Notification::where('user_id', $user->id)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'unread' => $unread,
                'read' => $read,
                'by_type' => $byType,
            ],
        ]);
    }

} 