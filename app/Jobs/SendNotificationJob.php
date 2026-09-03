<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Notifications\Channels\ChannelFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Notification $notification)
    {
        $this->onQueue($this->queueForPriority($notification->priority));
    }

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $notification = $this->notification;

        $notification->update([
            'status' => 'processing',
            'attempts' => $notification->attempts + 1,
        ]);

        $delivered = false;

        foreach ($notification->channels ?? [] as $channelName) {
            try {
                $channel = ChannelFactory::make($channelName);
                $channel->send($notification);

                $notification->logs()->create([
                    'channel' => $channelName,
                    'status' => 'success',
                    'response' => null,
                ]);

                $notification->update(['status' => 'sent']);
                $delivered = true;

                break;
            } catch (Throwable $e) {
                $notification->logs()->create([
                    'channel' => $channelName,
                    'status' => 'failed',
                    'response' => $e->getMessage(),
                ]);

                continue;
            }
        }

        if (! $delivered) {
            $notification->update(['status' => 'failed']);
        }
    }

    /**
     * Handle a job failure (all retries exhausted).
     */
    public function failed(?Throwable $exception): void
    {
        $this->notification->update(['status' => 'failed']);

        Log::error("SendNotificationJob failed for notification #{$this->notification->id}: " . ($exception?->getMessage() ?? 'unknown error'));
    }

    /**
     * Map a notification priority to its queue name.
     */
    protected function queueForPriority(string $priority): string
    {
        return match ($priority) {
            'high' => 'high',
            'low' => 'low',
            default => 'default',
        };
    }
}
