<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_own_profile_email(): void
    {
        $role = Role::query()->create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'name' => 'Admin',
            'email' => 'admin@milosevac.test',
        ]);

        $response = $this->actingAs($user)->put(route('account.profile.update'), [
            'name' => 'Glavni urednik',
            'email' => 'admin@milosevac.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Podaci naloga su uspjesno sacuvani.');

        $user->refresh();
        $this->assertSame('Glavni urednik', $user->name);
        $this->assertSame('admin@milosevac.com', $user->email);
    }

    public function test_profile_email_must_be_unique(): void
    {
        $role = Role::query()->create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'admin@milosevac.test',
        ]);
        User::factory()->create([
            'role_id' => $role->id,
            'email' => 'urednik@milosevac.com',
        ]);

        $response = $this->actingAs($user)->from(route('account.password.edit'))->put(route('account.profile.update'), [
            'name' => 'Admin',
            'email' => 'urednik@milosevac.com',
        ]);

        $response->assertRedirect(route('account.password.edit'));
        $response->assertSessionHasErrors('email');

        $this->assertSame('admin@milosevac.test', $user->fresh()->email);
    }

    public function test_authenticated_user_can_change_own_password(): void
    {
        $role = Role::query()->create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('current-password'),
        ]);

        $response = $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'current-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Lozinka je uspjesno promijenjena.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
        $this->assertFalse(Hash::check('current-password', $user->password));
    }

    public function test_current_password_is_required_to_change_password(): void
    {
        $role = Role::query()->create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('current-password'),
        ]);

        $response = $this->actingAs($user)->from(route('account.password.edit'))->put(route('account.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect(route('account.password.edit'));
        $response->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('current-password', $user->password));
    }
}
