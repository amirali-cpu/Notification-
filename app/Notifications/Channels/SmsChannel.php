<?php

namespace App\Notifications\Channels;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class SmsChannel implements NotificationChannelInterface
{
    public function send(Notification $notification): bool
    {
        // Stub: swap this out for a real SMS provider integration
        // (e.g. Twilio / Kavenegar) later. For now we just log the message.
        Log::info("SMS to {$notification->recipient}: {$notification->body}");

        return true;
    }
}
