<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use Filagora\Core\Support\UserModel;
use Filagora\Notifications\Enums\NotificationChannel;
use Filagora\Notifications\Events\PreferenceChanged;
use Filagora\Notifications\StandardCategories;
use Illuminate\Support\Facades\Schema;

/**
 * The site's user table still carries two columns the preference rows replaced: how often thread
 * mail arrives, and whether the person accepts private messages. Other parts of the site (the
 * members directory, the import and erasure tools) read them, so a change of choice is copied
 * there. A column the table lacks is skipped.
 */
final class SyncsLegacyNotificationColumns
{
    public function handle(PreferenceChanged $event): void
    {
        if ($event->channel !== NotificationChannel::EMAIL) {
            return;
        }

        [$attribute, $value] = match ($event->category) {
            StandardCategories::THREAD_UPDATES_DIGEST_FREQUENCY => ['thread_subscription_email_frequency', $event->new],
            StandardCategories::PRIVATE_MESSAGE_CONTACTABILITY => ['allow_private_messages', $event->new === '1'],
            default => [null, null],
        };

        $column = $attribute === null ? null : config("filagora-zencart.user_attributes.{$attribute}");

        if (! is_string($column) || $column === '') {
            return;
        }

        $model = UserModel::make();

        if (! Schema::hasColumn($model->getTable(), $column)) {
            return;
        }

        $model->newQuery()->whereKey($event->userId)->update([$column => $value]);
    }
}
