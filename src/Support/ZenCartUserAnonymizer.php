<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use Filagora\Compliance\Contracts\UserAnonymizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Who a Zen Cart site user is, replaced on erasure: the identity columns in
 * `filagora-zencart.anonymize.attributes` (columns the table lacks are skipped), an unusable
 * password, no roles or permissions, no two-factor, no avatar file, and no sessions or password
 * reset tokens left to log in with. Authored content stays, under the anonymous name.
 */
final class ZenCartUserAnonymizer implements UserAnonymizer
{
    public function anonymize(Model $user): void
    {
        $columns = Schema::getColumnListing($user->getTable());
        $email = (string) $user->getAttribute('email');
        $avatar = in_array('avatar_path', $columns, true) ? (string) $user->getAttribute('avatar_path') : '';

        DB::transaction(function () use ($user, $columns, $email): void {
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }

            if ($email !== '' && Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            foreach (['syncRoles', 'syncPermissions'] as $method) {
                if (method_exists($user, $method)) {
                    $user->{$method}([]);
                }
            }

            $values = [];

            foreach ((array) config('filagora-zencart.anonymize.attributes') as $column => $replacement) {
                if (is_string($column) && in_array($column, $columns, true)) {
                    $values[$column] = is_string($replacement) ? str_replace('{id}', (string) $user->getKey(), $replacement) : $replacement;
                }
            }

            foreach (['password' => Hash::make(Str::random(80)), 'remember_token' => Str::random(60)] as $column => $value) {
                if (in_array($column, $columns, true)) {
                    $values[$column] = $value;
                }
            }

            $user->forceFill($values);

            if (method_exists($user, 'disableTwoFactor')) {
                $user->disableTwoFactor();
            }

            $user->save();
        });

        if ($avatar !== '') {
            Storage::disk('public')->delete($avatar);
        }
    }
}
