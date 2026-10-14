<?php

declare(strict_types=1);

use Filagora\Compliance\Contracts\UserAnonymizer;
use Filagora\Compliance\Enums\PrivacyRequestStatus;
use Filagora\Compliance\Models\PrivacyRequest;
use Filagora\Compliance\Services\PrivacyRequests;
use Filagora\Compliance\Support\Abilities;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\ZenCart\Seeders\OperatorEntitlementSeeder;
use Filagora\ZenCart\Support\ZenCartUserAnonymizer;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AnonymizableUser extends TestUser
{
    public static array $calls = [];

    public function syncRoles(array $roles): void
    {
        self::$calls[] = 'roles';
    }

    public function syncPermissions(array $permissions): void
    {
        self::$calls[] = 'permissions';
    }

    public function disableTwoFactor(): void
    {
        self::$calls[] = 'two_factor';
    }
}

beforeEach(fn () => AnonymizableUser::$calls = []);

it('maps the six compliance abilities onto the site\'s operator keys, all of which the seeder defines', function () {
    $map = config('filagora-compliance.gates');

    expect($map)->toBe([
        'filagora.compliance.privacy_requests.view' => 'operator.privacy.requests.view',
        'filagora.compliance.privacy_requests' => 'operator.privacy.requests.update',
        'filagora.compliance.erase' => 'operator.privacy.requests.delete',
        'filagora.compliance.cookie_policies' => 'operator.cookie_policies.manage',
        'filagora.compliance.privacy_policies' => 'operator.privacy_policies.manage',
        'filagora.compliance.audit' => 'operator.audit.view',
    ]);

    foreach ($map as $key) {
        expect(OperatorEntitlementSeeder::LABELS)->toHaveKey($key);
    }
});

it('opens a compliance ability with the matching operator key and no other', function () {
    $user = TestUser::make('Officer');
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'operator.privacy.requests.delete', 'grant_type' => 'manual']);

    expect(Gate::forUser($user)->allows(Abilities::ERASE))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Abilities::MANAGE_REQUESTS))->toBeFalse()
        ->and(Gate::forUser($user)->allows(Abilities::AUDIT))->toBeFalse();
});

it('is the anonymiser the compliance package uses', function () {
    expect(app(UserAnonymizer::class))->toBeInstanceOf(ZenCartUserAnonymizer::class);
});

it('makes a user anonymous: identity columns replaced, password unusable, access and sessions gone', function () {
    $user = AnonymizableUser::make('Real Name');
    $original = $user->email;
    $oldPassword = $user->password;
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 's2', 'user_id' => TestUser::make('Other')->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => $original, 'token' => 'x', 'created_at' => now()]);
    DB::table('password_reset_tokens')->insert(['email' => 'someone-else@example.test', 'token' => 'y', 'created_at' => now()]);

    app(UserAnonymizer::class)->anonymize($user);

    $user->refresh();
    expect($user->name)->toBe('Deleted User '.$user->id)
        ->and($user->email)->toBe('deleted-user-'.$user->id.'@anonymized.invalid')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->password)->not->toBe($oldPassword)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['s2'])
        ->and(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['someone-else@example.test'])
        ->and(AnonymizableUser::$calls)->toBe(['roles', 'permissions', 'two_factor']);
});

it('deletes the avatar file and clears the columns this site has beyond the defaults', function () {
    Storage::fake('public');
    Schema::table('users', function ($table) {
        $table->string('avatar_path')->nullable();
        $table->string('username')->nullable();
        $table->boolean('is_banned')->default(false);
    });

    try {
        Storage::disk('public')->put('avatars/me.png', 'x');
        $user = TestUser::make('With Avatar');
        $user->forceFill(['avatar_path' => 'avatars/me.png', 'username' => 'realname', 'is_banned' => true])->save();

        app(UserAnonymizer::class)->anonymize($user);

        $user->refresh();
        expect($user->avatar_path)->toBeNull()
            ->and($user->username)->toBe('deleted_user_'.$user->id)
            ->and((bool) $user->is_banned)->toBeFalse()
            ->and(Storage::disk('public')->exists('avatars/me.png'))->toBeFalse();
    } finally {
        Schema::table('users', fn ($table) => $table->dropColumn(['avatar_path', 'username', 'is_banned']));
    }
});

it('erases a person end to end through a privacy request', function () {
    $user = AnonymizableUser::make('Requester');
    $request = PrivacyRequest::create(['user_id' => $user->id, 'type' => 'erasure', 'request_details' => 'Please'])->refresh();

    app(PrivacyRequests::class)->fulfilErasure($request, TestUser::make('Officer'));

    expect($request->refresh()->status)->toBe(PrivacyRequestStatus::FULFILLED)
        ->and($user->refresh()->email)->toContain('anonymized.invalid');
});
