<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('a worker with a temporary password must replace it before using the application', function () {
    $user = User::factory()->create([
        'password' => 'Temporary-Password-123!',
        'must_change_password' => true,
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertRedirect(route('password.temporary.edit'));

    Livewire::actingAs($user);
    Livewire::test('pages::auth.change-temporary-password')
        ->set('password', 'New-Secure-Password-123!')
        ->set('passwordConfirmation', 'New-Secure-Password-123!')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('New-Secure-Password-123!', $user->password))->toBeTrue();
});
