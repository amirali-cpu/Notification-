<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private function runJobFor(Notification $notification): void
    {
        (new SendNotificationJob($notification))->handle();
    }

    public function test_first_channel_success_creates_one_log_and_marks_sent(): void
    {
        // 'sms' is the log-based stub channel and always succeeds.
        $notification = Notification::factory()->channels(['sms', 'email'])->create();

        $this->runJobFor($notification);

        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertCount(1, $notification->logs);
        $this->assertSame('sms', $notification->logs->first()->channel);
        $this->assertSame('success', $notification->logs->first()->status);
    }

    public function test_falls_back_to_second_channel_when_first_fails(): void
    {
        // Force the real EmailChannel to fail, then let 'sms' succeed.
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP unreachable'));

        $notification = Notification::factory()->channels(['email', 'sms'])->create();

        $this->runJobFor($notification);

        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertCount(2, $notification->logs);

        $emailLog = $notification->logs->firstWhere('channel', 'email');
        $smsLog = $notification->logs->firstWhere('channel', 'sms');

        $this->assertSame('failed', $emailLog->status);
        $this->assertStringContainsString('SMTP unreachable', $emailLog->response);
        $this->assertSame('success', $smsLog->status);
    }

    public function test_all_channels_failing_marks_notification_failed_and_increments_attempts(): void
    {
        // Two unknown channel names: every attempt raises in the factory.
        $notification = Notification::factory()->channels(['bogus-one', 'bogus-two'])->create([
            'attempts' => 0,
        ]);

        $this->runJobFor($notification);

        $notification->refresh();
        $this->assertSame('failed', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertCount(2, $notification->logs);
        $this->assertTrue($notification->logs->every(fn ($log) => $log->status === 'failed'));
    }

    public function test_unknown_channel_name_produces_a_failed_log_without_crashing(): void
    {
        $notification = Notification::factory()->channels(['telegram'])->create();

        // Should not throw.
        $this->runJobFor($notification);

        $notification->refresh();
        $this->assertSame('failed', $notification->status);
        $this->assertCount(1, $notification->logs);

        $log = $notification->logs->first();
        $this->assertSame('telegram', $log->channel);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Unknown notification channel', $log->response);
    }
}
