<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /** Own notifications only - see NotificationPolicy. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Notification::class);

        return Notification::where('user_id', $request->user()->id)->orderByDesc('created_at')->get();
    }

    public function markRead(Notification $notification)
    {
        $this->authorize('update', $notification);

        return response()->json($this->notifications->markRead($notification));
    }
}
