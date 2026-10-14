<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Cms;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\TextInput;

/**
 * The user has not visited for at least this many days, by the last-seen column of the user model.
 * A user never seen counts as long absent. A guest never passes.
 */
final class NotVisitedDaysRule implements TargetingRule
{
    public function type(): string
    {
        return 'not_visited_days';
    }

    public function label(): string
    {
        return 'Has not visited for a number of days';
    }

    public function schema(): array
    {
        return [
            TextInput::make('days')->label('Days')->numeric()->minValue(1),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        $days = (int) ($rule['days'] ?? 0);

        if ($days <= 0) {
            return true;
        }

        $lastSeen = data_get($viewer->user, (string) config('filagora-zencart.user_attributes.last_seen_at'));

        if (! $lastSeen instanceof \DateTimeInterface) {
            return true;
        }

        return $lastSeen->getTimestamp() <= now()->subDays($days)->getTimestamp();
    }
}
