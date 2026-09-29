<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class AuditRepository
{
    private ?bool $tableExists = null;

    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function insert(
        ?int $actorUserId,
        string $action,
        string $entity,
        ?string $entityId,
        string $result,
        ?string $ip,
        ?string $userAgent,
        ?array $metadata
    ): void {
        if (!$this->tableExists()) {
            return;
        }

        $this->insertRow(
            $actorUserId,
            $action,
            $entity,
            $entityId,
            $result,
            $ip,
            $userAgent,
            $metadata
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function insertRequired(
        ?int $actorUserId,
        string $action,
        string $entity,
        ?string $entityId,
        string $result,
        ?string $ip,
        ?string $userAgent,
        ?array $metadata
    ): void {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Required audit storage is unavailable.');
        }

        $this->insertRow(
            $actorUserId,
            $action,
            $entity,
            $entityId,
            $result,
            $ip,
            $userAgent,
            $metadata
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function insertRow(
        ?int $actorUserId,
        string $action,
        string $entity,
        ?string $entityId,
        string $result,
        ?string $ip,
        ?string $userAgent,
        ?array $metadata
    ): void {

        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO auditoria_eventos (
                actor_usuario_id,
                accion,
                entidad,
                entidad_id,
                resultado,
                ip,
                user_agent,
                metadata_json
            ) VALUES (
                :actor_usuario_id,
                :accion,
                :entidad,
                :entidad_id,
                :resultado,
                :ip,
                :user_agent,
                :metadata_json
            )
            SQL
        );
        $statement->execute([
            'actor_usuario_id' => $actorUserId,
            'accion' => $action,
            'entidad' => $entity,
            'entidad_id' => $entityId,
            'resultado' => $result,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'metadata_json' => $metadata === null
                ? null
                : json_encode(
                    $metadata,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
        ]);
    }

    public function tableExists(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => 'auditoria_eventos']);

        $this->tableExists = (int) $statement->fetchColumn() === 1;

        return $this->tableExists;
    }
}
