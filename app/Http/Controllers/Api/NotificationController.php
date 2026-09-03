<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationRequest;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Store a new notification and dispatch it unless it is scheduled for the future.
     */
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $notification = Notification::create([
            'recipient' => $data['recipient'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'channels' => $data['channels'],
            'priority' => $data['priority'] ?? 'medium',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        // Send now when there is no schedule or the scheduled time has already arrived.
        // Future-dated notifications stay 'pending' for the scheduler to pick up.
        if (is_null($notification->scheduled_at) || $notification->scheduled_at->isPast()) {
            SendNotificationJob::dispatch($notification);
        }

        return response()->json($notification, 201);
    }

    /**
     * Show a single notification with its delivery logs.
     */
    public function show(Notification $notification): JsonResponse
    {
        $notification->load('logs');

        return response()->json($notification);
    }

    /**
     * Return a lightweight status payload for quick polling.
     */
    public function status(Notification $notification): JsonResponse
    {
        return response()->json([
            'id' => $notification->id,
            'status' => $notification->status,
            'attempts' => $notification->attempts,
            'channels' => $notification->channels,
            'logs_count' => $notification->logs()->count(),
        ]);
    }

    /**
     * List notifications, newest first, with optional status and channel filters.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('channel'), fn ($query, $channel) => $query->whereJsonContains('channels', $channel))
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($notifications);
    }
}
