<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Infrastructure\Repositories\PermissionRepository;

final class PermissionService
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    public function allows(int $userId, string $permissionCode): bool
    {
        if ($userId < 1) {
            return false;
        }

        return $this->permissions->userHasPermission(
            $userId,
            self::normalizeCode($permissionCode)
        );
    }

    public static function normalizeCode(string $permissionCode): string
    {
        $permissionCode = strtolower(trim($permissionCode));

        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/', $permissionCode) !== 1
            || strlen($permissionCode) > 120
        ) {
            throw new \InvalidArgumentException('Invalid permission code.');
        }

        return $permissionCode;
    }
}
