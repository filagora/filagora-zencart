<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the old vBulletin forum still knows about an imported member. Read, never written: the
 * caller shows it on a page and the member's own record is left as it is.
 */
final class OldForumProfile
{
    /** The users-table field, then the old columns it may be found in, in the order tried. */
    private const COLUMNS = [
        'name' => ['realname', 'field5'],
        'biography' => ['biography', 'aboutme', 'field1'],
        'location' => ['location', 'field2'],
        'interests' => ['interests', 'field3'],
        'occupation' => ['occupation', 'field4'],
    ];

    /**
     * Fills the member's empty profile fields on the model (not in the database) from the old
     * forum. The real name counts as empty when it is blank or only repeats the username.
     */
    public function fill(Model $user): void
    {
        if (! $this->enabled()) {
            return;
        }

        $missing = [];

        foreach (['biography', 'location', 'interests', 'occupation'] as $field) {
            if (! filled($user->getAttribute($field))) {
                $missing[] = $field;
            }
        }

        $name = trim((string) $user->getAttribute('name'));
        $username = trim((string) $user->getAttribute('username'));

        if ($name === '' || ($username !== '' && strcasecmp($name, $username) === 0)) {
            $missing[] = 'name';
        }

        $legacyId = $missing === [] ? null : $this->legacyId($user);

        if ($legacyId === null || ! Schema::hasTable((string) config('filagora-zencart.old_forum_profile.userfield_table'))) {
            return;
        }

        $row = DB::table((string) config('filagora-zencart.old_forum_profile.userfield_table'))->where('userid', $legacyId)->first();

        if ($row === null) {
            return;
        }

        foreach ($missing as $field) {
            foreach (self::COLUMNS[$field] as $column) {
                $value = $row->{$column} ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $user->setAttribute($field, trim($value));

                    break;
                }
            }
        }
    }

    /**
     * The unix time the member was last active on the old forum, or null.
     */
    public function lastActivity(Model $user): ?int
    {
        $table = (string) config('filagora-zencart.old_forum_profile.user_table');
        $legacyId = $this->enabled() ? $this->legacyId($user) : null;

        if ($legacyId === null || ! Schema::hasTable($table)) {
            return null;
        }

        $last = DB::table($table)->where('userid', $legacyId)->value('lastactivity');

        return is_numeric($last) && (int) $last > 0 ? (int) $last : null;
    }

    private function enabled(): bool
    {
        return (bool) config('filagora-zencart.old_forum_profile.enabled', true);
    }

    private function legacyId(Model $user): ?int
    {
        $table = (string) config('filagora-zencart.old_forum_profile.member_map_table');

        if (! Schema::hasTable($table)) {
            return null;
        }

        $id = DB::table($table)->where('user_id', $user->getKey())->value('legacy_user_id');

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}
