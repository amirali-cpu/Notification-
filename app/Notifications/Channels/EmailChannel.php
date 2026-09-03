<?php

namespace App\Notifications\Channels;

use App\Models\Notification;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class EmailChannel implements NotificationChannelInterface
{
    public function send(Notification $notification): bool
    {
        try {
            Mail::raw($notification->body, function ($message) use ($notification) {
                $message->to($notification->recipient)
                    ->subject($notification->title ?? 'Notification');
            });

            return true;
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Failed to send email to {$notification->recipient}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
