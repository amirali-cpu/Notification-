<?php

namespace Database\Factories;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient' => $this->faker->safeEmail(),
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'channels' => ['sms', 'email'],
            'priority' => 'medium',
            'scheduled_at' => null,
            'status' => 'pending',
            'attempts' => 0,
        ];
    }

    /**
     * Notification scheduled for a past moment (due for dispatch).
     */
    public function scheduledInThePast(): static
    {
        return $this->state(fn () => ['scheduled_at' => now()->subMinute()]);
    }

    /**
     * Notification scheduled for a future moment (not yet due).
     */
    public function scheduledInTheFuture(): static
    {
        return $this->state(fn () => ['scheduled_at' => now()->addDay()]);
    }

    /**
     * Notification that has already been sent.
     */
    public function sent(): static
    {
        return $this->state(fn () => ['status' => 'sent', 'attempts' => 1]);
    }

    /**
     * Give the notification an explicit ordered channel list.
     *
     * @param  list<string>  $channels
     */
    public function channels(array $channels): static
    {
        return $this->state(fn () => ['channels' => $channels]);
    }
}
