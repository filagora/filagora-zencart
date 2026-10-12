<?php

declare(strict_types=1);

namespace ForumKit\ZenCart\Tests\Support;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class TestUser extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> */
    public array $roles = [];

    public static function make(string $name = 'User'): self
    {
        return self::create([
            'name' => $name,
            'email' => strtolower($name).uniqid().'@example.test',
            'password' => 'x',
            'email_verified_at' => now(),
        ]);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
