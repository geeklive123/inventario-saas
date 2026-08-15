<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\MembershipStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership} */
function branchOwnerContext(string $companyName): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => $companyName,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->firstOrFail()->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())
        ->where('user_id', $user->getKey())
        ->firstOrFail();

    return compact('user', 'company', 'membership');
}

function livewireSnapshot(string $html, string $componentName): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encodedSnapshot) {
        $snapshot = html_entity_decode($encodedSnapshot, ENT_QUOTES | ENT_HTML5);
        $decodedSnapshot = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        if (($decodedSnapshot['memo']['name'] ?? null) === $componentName) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Livewire snapshot [{$componentName}] was not found.");
}

test('an active owner can enter branch administration and authorize its Livewire actions', function () {
    $context = branchOwnerContext('Owner Branch Company');

    $page = $this->actingAs($context['user'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('configuration.branches'))
        ->assertSuccessful()
        ->assertSee('Sucursales');

    app()->forgetScopedInstances();

    $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => livewireSnapshot($page->getContent(), 'pages::settings.branches'),
            'updates' => [],
            'calls' => [[
                'method' => 'create',
                'params' => [],
                'metadata' => [],
            ]],
        ]],
    ])->assertSuccessful();
});

test('a non owner without permission receives forbidden', function () {
    $context = branchOwnerContext('Restricted Branch Company');
    $member = User::factory()->create();
    $membership = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $member->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);

    $this->actingAs($member)
        ->withSession(['current_membership_id' => $membership->getKey()])
        ->get(route('configuration.branches'))
        ->assertForbidden();
});

test('a user cannot select a membership from another company owner', function () {
    $first = branchOwnerContext('First Branch Company');
    $second = branchOwnerContext('Second Branch Company');

    $this->actingAs($first['user'])
        ->withSession(['current_membership_id' => $second['membership']->getKey()])
        ->get(route('configuration.branches'))
        ->assertForbidden();
});

test('an owner cannot access a branch resource from another company', function () {
    $first = branchOwnerContext('Scoped Branch Company');
    $second = branchOwnerContext('Foreign Branch Company');
    $foreignBranch = Branch::factory()->create(['company_id' => $second['company']->getKey()]);

    $page = $this->actingAs($first['user'])
        ->withSession(['current_membership_id' => $first['membership']->getKey()])
        ->get(route('configuration.branches'))
        ->assertSuccessful();

    app()->forgetScopedInstances();

    $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => livewireSnapshot($page->getContent(), 'pages::settings.branches'),
            'updates' => [],
            'calls' => [[
                'method' => 'edit',
                'params' => [$foreignBranch->getKey()],
                'metadata' => [],
            ]],
        ]],
    ])->assertNotFound();

    app(CurrentCompany::class)->set($first['membership']);

    expect(Branch::query()->find($foreignBranch->getKey()))->toBeNull()
        ->and(Gate::forUser($first['user'])->allows('view', $foreignBranch))->toBeFalse()
        ->and(Gate::forUser($first['user'])->allows('update', $foreignBranch))->toBeFalse();
});
