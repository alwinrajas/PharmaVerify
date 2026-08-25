<?php

namespace App\Support;

final class Roles
{
    public const ADMINISTRATOR = 'Administrator';
    public const SUPERVISOR = 'Supervisor';
    public const SHOP_USER = 'Shop User';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::ADMINISTRATOR, self::SUPERVISOR, self::SHOP_USER];
    }
}
