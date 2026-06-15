<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetUserPassword extends Command
{
    protected $signature = 'users:set-password {email : Existing user email address}';

    protected $description = 'Securely replace a user password without exposing it in shell history.';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if (! $user) {
            $this->error('User was not found.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm new password');
        if (strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::FAILURE;
        }
        if (! hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => null,
        ])->save();

        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
