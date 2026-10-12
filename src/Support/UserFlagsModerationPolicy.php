<?php

declare(strict_types=1);

namespace ForumKit\ZenCart\Support;

use ForumKit\Forums\Contracts\ContentModerationPolicy;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Two flags on the host user decide who is moderated: "always moderate" (everything waits), and
 * "moderate first post" (everything waits until the user has had something approved, and they
 * may not post again while their first submission is still in the queue).
 */
final class UserFlagsModerationPolicy implements ContentModerationPolicy
{
    public function requiresModeration(Authenticatable $user): bool
    {
        if ($this->flag($user, 'always_moderate')) {
            return true;
        }

        return $this->flag($user, 'moderate_first_post') && ! $this->hasApprovedContent($user);
    }

    public function awaitingFirstReview(Authenticatable $user): bool
    {
        if (! $this->flag($user, 'moderate_first_post') || $this->hasApprovedContent($user)) {
            return false;
        }

        return $this->hasContentWithStatus($user, ContentModerationStatus::PENDING);
    }

    private function flag(Authenticatable $user, string $key): bool
    {
        return (bool) ($user->{(string) config("forumkit-zencart.user_attributes.{$key}")} ?? false);
    }

    /**
     * Approved only: a pending first post must not count as "has posted before".
     */
    private function hasApprovedContent(Authenticatable $user): bool
    {
        return $this->hasContentWithStatus($user, ContentModerationStatus::APPROVED);
    }

    private function hasContentWithStatus(Authenticatable $user, ContentModerationStatus $status): bool
    {
        $id = $user->getAuthIdentifier();

        return Models::class('thread')::query()->where('author_id', $id)->where('moderation_status', $status->value)->exists()
            || Models::class('post')::query()->where('author_id', $id)->where('moderation_status', $status->value)->exists();
    }
}
