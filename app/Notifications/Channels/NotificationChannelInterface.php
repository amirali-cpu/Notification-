<?php

namespace App\Notifications\Channels;

use App\Models\Notification;

interface NotificationChannelInterface
{
    /**
     * Deliver the notification through this channel.
     *
     * Implementations must return true on success and throw an exception
     * (with a descriptive message) on any failure.
     *
     * @throws \RuntimeException
     */
    public function send(Notification $notification): bool;
}
