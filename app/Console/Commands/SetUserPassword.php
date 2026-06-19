<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SetUserPassword extends Command
{
    protected $signature = 'users:set-password
        {email : User email address}
        {--password= : Set password non-interactively. Minimum 12 characters.}
        {--create-admin : Create the user as a super admin if it does not exist.}';

    protected $description = 'Replace a user password and optionally create a missing super admin account.';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if (! $user) {
            if (! $this->option('create-admin')) {
                $this->error('User was not found. Use --create-admin to create it as a super admin.');

                return self::FAILURE;
            }

            $role = $this->ensureSuperAdminRole();
            $email = (string) $this->argument('email');
            $user = User::query()->create([
                'role_id' => $role->id,
                'name' => 'Glavni urednik',
                'slug' => Str::slug(Str::before($email, '@')) ?: 'glavni-urednik',
                'email' => $email,
                'bio' => 'Urednistvo portala Milosevac.',
                'password' => Hash::make(Str::password(24)),
            ]);
            $this->info("Created super admin account for {$user->email}.");
        }

        $password = (string) ($this->option('password') ?: $this->secret('New password (minimum 12 characters)'));
        $confirmation = $this->option('password') ? $password : (string) $this->secret('Confirm new password');
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

    private function ensureSuperAdminRole(): Role
    {
        $permissions = collect([
            'manage_posts' => 'Upravljanje clancima',
            'publish_posts' => 'Objava clanaka',
            'manage_taxonomy' => 'Upravljanje kategorijama i tagovima',
            'manage_users' => 'Upravljanje korisnicima',
            'manage_settings' => 'Globalne postavke',
        ])->map(fn ($label, $name) => Permission::query()->firstOrCreate(['name' => $name], ['label' => $label]));

        $role = Role::query()->firstOrCreate(['name' => 'super_admin'], ['label' => 'Super Admin']);
        $role->permissions()->sync($permissions->pluck('id')->all());

        return $role;
    }
}
