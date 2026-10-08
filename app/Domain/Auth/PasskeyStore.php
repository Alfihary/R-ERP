<?php
declare(strict_types=1);
namespace App\Domain\Auth;

interface PasskeyStore
{
    public function ready(): bool;
    public function hasAny(): bool;
    public function forUser(int $userId): array;
    public function create(int $userId, array $credential): void;
    public function find(string $credentialId): ?array;
    public function accept(array $credential, int $counter): bool;
    public function revoke(int $userId, int $credentialId): bool;
}
