<?php

namespace App\Notifications\Channels;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class PushChannel implements NotificationChannelInterface
{
    public function send(Notification $notification): bool
    {
        // Stub: swap this out for a real push provider integration
        // (e.g. Firebase Cloud Messaging / APNs) later. For now we just log the message.
        Log::info("PUSH to {$notification->recipient}: {$notification->title} - {$notification->body}");

        return true;
    }
}
