<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use Filagora\Forums\Contracts\PostSignatureResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The signature is a column on the host user; a blank one means none.
 */
final class UserSignatureResolver implements PostSignatureResolver
{
    public function signatureFor(Authenticatable $user): ?string
    {
        $signature = (string) ($user->{(string) config('filagora-zencart.user_attributes.signature')} ?? '');

        return trim($signature) !== '' ? $signature : null;
    }
}
