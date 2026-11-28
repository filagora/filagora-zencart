<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * What the database still holds from the old vBulletin forum about an imported member: the link to
 * their old account, and that account's rows in the user and profile field tables. All of it goes
 * into their data export, and erasure deletes it. Their posts are not touched. A table that is not
 * there is passed over.
 */
final class OldForumDataContributor implements PrivacyContributor
{
    /** The old user columns an export carries; the rest are credentials or forum settings. */
    private const ACCOUNT_COLUMNS = [
        'username', 'email', 'parentemail', 'usertitle', 'homepage', 'birthday', 'ipaddress',
        'joindate', 'lastvisit', 'lastactivity', 'icq', 'aim', 'yahoo', 'msn', 'skype', 'fbuserid', 'fbname',
    ];

    public function key(): string
    {
        return 'old_forum';
    }

    public function export(Model $user): array
    {
        return [
            'accounts' => $this->linksOf($user)->map(function (stdClass $link): array {
                $oldId = $link->legacy_user_id;

                return [
                    'link' => $this->readable(Arr::except((array) $link, ['id', 'user_id'])),
                    'account' => $this->readable(Arr::only($this->oldRow('user_table', $oldId), self::ACCOUNT_COLUMNS)),
                    'profile_fields' => $this->readable(Arr::except($this->oldRow('userfield_table', $oldId), ['userid'])),
                ];
            })->values()->all(),
        ];
    }

    public function erase(Model $user): void
    {
        $links = $this->linksOf($user);

        if ($links->isEmpty()) {
            return;
        }

        // The old rows first: the link is how they are found.
        foreach (['userfield_table', 'user_table'] as $key) {
            if (Schema::hasTable($this->table($key))) {
                DB::table($this->table($key))->whereIn('userid', $links->pluck('legacy_user_id')->all())->delete();
            }
        }

        DB::table($this->table('member_map_table'))->where('user_id', $user->getKey())->delete();
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function linksOf(Model $user): Collection
    {
        $table = $this->table('member_map_table');

        if (! Schema::hasTable($table)) {
            return collect();
        }

        return DB::table($table)->where('user_id', $user->getKey())->orderBy('legacy_user_id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function oldRow(string $key, mixed $oldId): array
    {
        $table = $this->table($key);

        return Schema::hasTable($table) ? (array) DB::table($table)->where('userid', $oldId)->first() : [];
    }

    /**
     * Old forum text may hold bytes that are not UTF-8, which an export in JSON cannot carry.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function readable(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => is_string($value) ? mb_scrub($value) : $value, $values);
    }

    private function table(string $key): string
    {
        return (string) config("filagora-zencart.old_forum_profile.{$key}");
    }
}
