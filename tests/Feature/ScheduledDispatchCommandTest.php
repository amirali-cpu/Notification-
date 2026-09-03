<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledDispatchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_notification_scheduled_in_the_past_is_dispatched(): void
    {
        Queue::fake();

        $notification = Notification::factory()->scheduledInThePast()->create([
            'status' => 'pending',
        ]);

        $this->artisan('notifications:dispatch-scheduled')
            ->expectsOutputToContain('Dispatched 1 scheduled notification(s).')
            ->assertExitCode(0);

        Queue::assertPushed(SendNotificationJob::class, 1);
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->notification->is($notification),
        );
    }

    public function test_pending_notification_scheduled_in_the_future_is_not_dispatched(): void
    {
        Queue::fake();

        Notification::factory()->scheduledInTheFuture()->create([
            'status' => 'pending',
        ]);

        $this->artisan('notifications:dispatch-scheduled')
            ->expectsOutputToContain('No scheduled notifications due.')
            ->assertExitCode(0);

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_already_sent_notification_is_not_redispatched(): void
    {
        Queue::fake();

        Notification::factory()->scheduledInThePast()->sent()->create();

        $this->artisan('notifications:dispatch-scheduled')
            ->expectsOutputToContain('No scheduled notifications due.')
            ->assertExitCode(0);

        Queue::assertNotPushed(SendNotificationJob::class);
    }
}
