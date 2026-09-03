<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'recipient' => 'user@example.com',
            'title' => 'Welcome',
            'body' => 'Thanks for signing up!',
            'channels' => ['sms', 'email'],
            'priority' => 'high',
        ], $overrides);
    }

    public function test_creating_a_notification_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/notifications', $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_creating_a_notification_with_valid_data_and_auth_returns_201_and_persists_pending(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $response = $this->postJson('/api/notifications', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('recipient', 'user@example.com')
            ->assertJsonPath('channels', ['sms', 'email']);

        $this->assertDatabaseHas('notifications', [
            'recipient' => 'user@example.com',
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_creating_a_notification_with_invalid_data_returns_422(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $response = $this->postJson('/api/notifications', [
            'recipient' => 'user@example.com',
            // body missing
            'channels' => ['carrier-pigeon'], // invalid channel name
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['body', 'channels.0']);
    }

    public function test_future_scheduled_notification_does_not_dispatch_job(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $response = $this->postJson('/api/notifications', $this->validPayload([
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]));

        $response->assertCreated()->assertJsonPath('status', 'pending');

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_immediate_notification_dispatches_job(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $response = $this->postJson('/api/notifications', $this->validPayload());

        $response->assertCreated();

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_fetching_a_single_notification_returns_it_with_logs(): void
    {
        $this->actingAsUser();

        $notification = Notification::factory()->create();
        NotificationLog::create([
            'notification_id' => $notification->id,
            'channel' => 'sms',
            'status' => 'success',
            'response' => null,
        ]);
        NotificationLog::create([
            'notification_id' => $notification->id,
            'channel' => 'email',
            'status' => 'failed',
            'response' => 'SMTP unreachable',
        ]);

        $response = $this->getJson("/api/notifications/{$notification->id}");

        $response->assertOk()
            ->assertJsonPath('id', $notification->id)
            ->assertJsonCount(2, 'logs')
            ->assertJsonPath('logs.0.channel', 'sms')
            ->assertJsonPath('logs.1.channel', 'email');
    }

    public function test_fetching_a_non_existent_notification_returns_404(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/notifications/999999');

        $response->assertStatus(404);
    }

    public function test_index_filters_by_status_and_by_channel(): void
    {
        $this->actingAsUser();

        $pendingSms = Notification::factory()->channels(['sms'])->create(['status' => 'pending']);
        $sentSms = Notification::factory()->channels(['sms'])->sent()->create();
        $pendingPush = Notification::factory()->channels(['push'])->create(['status' => 'pending']);

        // Filter by status
        $this->getJson('/api/notifications?status=pending')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $pendingSms->id])
            ->assertJsonFragment(['id' => $pendingPush->id])
            ->assertJsonMissing(['id' => $sentSms->id]);

        // Filter by channel (whereJsonContains)
        $this->getJson('/api/notifications?channel=sms')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $pendingSms->id])
            ->assertJsonFragment(['id' => $sentSms->id])
            ->assertJsonMissing(['id' => $pendingPush->id]);

        // Combined
        $this->getJson('/api/notifications?status=pending&channel=sms')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $pendingSms->id]);
    }

    public function test_status_endpoint_returns_the_correct_shape(): void
    {
        $this->actingAsUser();

        $notification = Notification::factory()->channels(['sms', 'email'])->create([
            'status' => 'processing',
            'attempts' => 2,
        ]);
        NotificationLog::create([
            'notification_id' => $notification->id,
            'channel' => 'sms',
            'status' => 'failed',
            'response' => 'boom',
        ]);

        $response = $this->getJson("/api/notifications/{$notification->id}/status");

        $response->assertOk()
            ->assertExactJson([
                'id' => $notification->id,
                'status' => 'processing',
                'attempts' => 2,
                'channels' => ['sms', 'email'],
                'logs_count' => 1,
            ]);
    }
}
