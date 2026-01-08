<?php

declare(strict_types=1);

namespace App\Http\Controllers\Common;

use Illuminate\Broadcasting\BroadcastController as BaseBroadcastController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Broadcast;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use function is_array;


class BroadcastController extends BaseBroadcastController
{
    /**
     * Authenticate the current user.
     *
     * @return Response
     */
    public function authenticateUser(Request $request)
    {
        if ($request->hasSession() && method_exists($request->session(), 'reflash')) {
            $request->session()->reflash();
        }

        $result = Broadcast::resolveAuthenticatedUser($request);

        if (null === $result) {
            throw new AccessDeniedHttpException();
        }

        // Fix: The default implementation might return an array if the driver returns one
        // (like Ably/Pusher), but the method signature requires a Response object.
        if (is_array($result)) {
            return new Response(json_encode($result), 200, ['Content-Type' => 'application/json']);
        }

        return $result;
    }
}
