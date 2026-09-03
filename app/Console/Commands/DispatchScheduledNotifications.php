<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchScheduledNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:dispatch-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch pending notifications whose scheduled time has arrived';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $due = Notification::query()
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No scheduled notifications due.');

            return self::SUCCESS;
        }

        foreach ($due as $notification) {
            SendNotificationJob::dispatch($notification);
        }

        $count = $due->count();

        Log::info("Dispatched {$count} scheduled notification(s).", [
            'notification_ids' => $due->pluck('id')->all(),
        ]);

        $this->info("Dispatched {$count} scheduled notification(s).");

        return self::SUCCESS;
    }
}
