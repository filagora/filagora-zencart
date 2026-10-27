<?php

declare(strict_types=1);

use Filagora\ZenCart\Legacy\OldForumProfile;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function oldForumTables(): void
{
    Schema::create('legacy_import_user_maps', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('legacy_user_id');
    });
    Schema::create('vb_userfield', function (Blueprint $table): void {
        $table->unsignedBigInteger('userid');
        $table->text('biography')->nullable();
        $table->text('aboutme')->nullable();
        $table->string('field2')->nullable();
        $table->string('field5')->nullable();
    });
    Schema::create('vb_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('userid');
        $table->unsignedBigInteger('lastactivity')->default(0);
    });
}

afterEach(function () {
    Schema::dropIfExists('legacy_import_user_maps');
    Schema::dropIfExists('vb_userfield');
    Schema::dropIfExists('vb_user');
});

it('fills a members empty profile fields from the old forum without saving them', function () {
    oldForumTables();
    $user = TestUser::make('Skynet');
    $user->setAttribute('username', 'Skynet'); // a real name that only repeats the username counts as empty
    DB::table('legacy_import_user_maps')->insert(['user_id' => $user->id, 'legacy_user_id' => 77]);
    DB::table('vb_userfield')->insert(['userid' => 77, 'aboutme' => ' Plugin author ', 'field2' => 'Leeds', 'field5' => 'Sam Real']);

    app(OldForumProfile::class)->fill($user);

    expect($user->biography)->toBe('Plugin author')
        ->and($user->location)->toBe('Leeds')
        ->and($user->name)->toBe('Sam Real')
        ->and($user->fresh()->biography)->toBeNull();
});

it('keeps what the member already has', function () {
    oldForumTables();
    $user = TestUser::make('Skynet');
    $user->setAttribute('biography', 'Mine');
    $user->setAttribute('name', 'Sky Net');
    DB::table('legacy_import_user_maps')->insert(['user_id' => $user->id, 'legacy_user_id' => 77]);
    DB::table('vb_userfield')->insert(['userid' => 77, 'biography' => 'Old', 'field5' => 'Old Name']);

    app(OldForumProfile::class)->fill($user);

    expect($user->biography)->toBe('Mine')->and($user->name)->toBe('Sky Net');
});

it('reports when the member was last active on the old forum', function () {
    oldForumTables();
    $user = TestUser::make('Skynet');
    DB::table('legacy_import_user_maps')->insert(['user_id' => $user->id, 'legacy_user_id' => 77]);
    DB::table('vb_user')->insert(['userid' => 77, 'lastactivity' => 1700000000]);

    expect(app(OldForumProfile::class)->lastActivity($user))->toBe(1700000000);
});

it('does nothing when the old tables are not there', function () {
    $user = TestUser::make('Skynet');

    app(OldForumProfile::class)->fill($user);

    expect($user->biography)->toBeNull()->and(app(OldForumProfile::class)->lastActivity($user))->toBeNull();
});

it('does nothing when switched off', function () {
    oldForumTables();
    config(['filagora-zencart.old_forum_profile.enabled' => false]);
    $user = TestUser::make('Skynet');
    DB::table('legacy_import_user_maps')->insert(['user_id' => $user->id, 'legacy_user_id' => 77]);
    DB::table('vb_userfield')->insert(['userid' => 77, 'biography' => 'Old']);

    app(OldForumProfile::class)->fill($user);

    expect($user->biography)->toBeNull();
});
