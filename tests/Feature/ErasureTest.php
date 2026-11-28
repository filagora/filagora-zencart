<?php

declare(strict_types=1);

use Filagora\Compliance\Models\PrivacyRequest;
use Filagora\Compliance\Services\PersonalDataExport;
use Filagora\Compliance\Services\PrivacyRequests;
use Filagora\ZenCart\Legacy\OldForumProfile;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ERASED_PROFILE_COLUMNS = ['biography', 'location', 'interests', 'occupation', 'user_title', 'timezone', 'stripe_id', 'pm_type', 'pm_last_four'];

function importedMemberTables(): void
{
    Schema::create('legacy_import_user_maps', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('legacy_user_id');
        $table->string('legacy_username')->nullable();
        $table->string('legacy_email')->nullable();
    });
    Schema::create('vb_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('userid');
        $table->string('username');
        $table->string('email');
        $table->string('password');
        $table->string('salt');
        $table->unsignedBigInteger('lastactivity')->default(0);
    });
    Schema::create('vb_userfield', function (Blueprint $table): void {
        $table->unsignedBigInteger('userid');
        $table->text('field1')->nullable();
        $table->string('field2')->nullable();
    });
}

function importedMember(string $name, int $oldId): TestUser
{
    $user = TestUser::make($name);
    DB::table('legacy_import_user_maps')->insert(['user_id' => $user->id, 'legacy_user_id' => $oldId, 'legacy_username' => "old {$name}", 'legacy_email' => "old-{$oldId}@example.test"]);
    DB::table('vb_user')->insert(['userid' => $oldId, 'username' => "old {$name}", 'email' => "old-{$oldId}@example.test", 'password' => 'old-hash', 'salt' => 'old-salt', 'lastactivity' => 1700000000]);
    DB::table('vb_userfield')->insert(['userid' => $oldId, 'field1' => "About {$name}", 'field2' => 'Leeds']);

    return $user;
}

function eraseThroughRequest(TestUser $user): void
{
    $request = PrivacyRequest::create(['user_id' => $user->id, 'type' => 'erasure', 'request_details' => 'Please'])->refresh();

    app(PrivacyRequests::class)->fulfilErasure($request, TestUser::make('Officer'));
}

afterEach(function () {
    Schema::dropIfExists('legacy_import_user_maps');
    Schema::dropIfExists('vb_user');
    Schema::dropIfExists('vb_userfield');
});

it('empties the profile columns on erasure', function () {
    Schema::table('users', function (Blueprint $table): void {
        foreach (ERASED_PROFILE_COLUMNS as $column) {
            $table->string($column)->nullable();
        }
    });

    try {
        $user = TestUser::make('Member');
        $user->forceFill(array_fill_keys(ERASED_PROFILE_COLUMNS, 'mine'))->save();

        eraseThroughRequest($user);

        $user->refresh();

        foreach (ERASED_PROFILE_COLUMNS as $column) {
            expect($user->getAttribute($column))->toBeNull($column);
        }
    } finally {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(ERASED_PROFILE_COLUMNS));
    }
});

it('removes an imported member from the old forum tables on erasure, and nobody else', function () {
    importedMemberTables();
    $user = importedMember('Member', 77);
    importedMember('Other', 78);

    eraseThroughRequest($user);

    expect(DB::table('legacy_import_user_maps')->pluck('legacy_user_id')->all())->toBe([78])
        ->and(DB::table('vb_user')->pluck('userid')->all())->toBe([78])
        ->and(DB::table('vb_userfield')->pluck('userid')->all())->toBe([78]);

    $erased = $user->refresh();
    app(OldForumProfile::class)->fill($erased);

    expect($erased->getAttribute('biography'))->toBeNull()
        ->and(app(OldForumProfile::class)->lastActivity($erased))->toBeNull();
});

it('erases without the old forum tables', function () {
    $user = TestUser::make('Member');

    eraseThroughRequest($user);

    expect($user->refresh()->email)->toContain('anonymized.invalid');
});

it('exports what the old forum holds about a member, without its password', function () {
    importedMemberTables();
    $user = importedMember('Member', 77);
    importedMember('Other', 78);

    $export = app(PersonalDataExport::class)->build($user);

    expect($export)->toHaveKey('old_forum')
        ->and($export['old_forum']['accounts'])->toHaveCount(1)
        ->and($export['old_forum']['accounts'][0]['link'])->toMatchArray(['legacy_user_id' => 77, 'legacy_username' => 'old Member', 'legacy_email' => 'old-77@example.test'])
        ->and($export['old_forum']['accounts'][0]['account'])->toMatchArray(['username' => 'old Member', 'email' => 'old-77@example.test', 'lastactivity' => 1700000000])
        ->and($export['old_forum']['accounts'][0]['profile_fields'])->toBe(['field1' => 'About Member', 'field2' => 'Leeds'])
        ->and(json_encode($export))->not->toContain('old-hash', 'old-salt', 'old Other');
});
