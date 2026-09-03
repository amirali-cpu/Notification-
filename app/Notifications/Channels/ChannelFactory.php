<?php

namespace App\Notifications\Channels;

use InvalidArgumentException;

class ChannelFactory
{
    public static function make(string $channelName): NotificationChannelInterface
    {
        return match (strtolower($channelName)) {
            'email' => new EmailChannel(),
            'sms' => new SmsChannel(),
            'push' => new PushChannel(),
            default => throw new InvalidArgumentException("Unknown notification channel: {$channelName}"),
        };
    }
}
