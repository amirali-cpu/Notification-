<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateTestUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-test-user
                            {--email=test@example.com : Email for the test user}
                            {--password=password : Password for the test user}
                            {--name=Test User : Name for the test user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create (or update) a test user for hitting the API locally';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email');
        $password = $this->option('password');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name'),
                'password' => Hash::make($password),
            ],
        );

        $this->info("Test user ready: {$user->email} (password: {$password})");

        return self::SUCCESS;
    }
}
