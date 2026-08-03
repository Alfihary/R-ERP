<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class CredentialVerificationService
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verificarTokenPublico(string $token): ?array
    {
        $token = trim($token);

        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $record = $this->publicVerificationByHash(hash('sha256', $token));

        if ($record === null || !$this->isValidRecord($record)) {
            return null;
        }

        $fullName = trim(implode(' ', array_filter([
            $record['primer_nombre'] ?? null,
            $record['segundo_nombre'] ?? null,
            $record['apellido_paterno'] ?? null,
            $record['apellido_materno'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        return [
            'estado' => 'Credencial válida',
            'nombre_completo' => $fullName !== '' ? $fullName : (string) ($record['username'] ?? ''),
            'puesto' => $this->optionalString($record['puesto'] ?? null),
            'ubicacion' => $this->optionalString($record['ubicacion_publica'] ?? null),
            'emitida_en' => $record['credencial_emitida_en'] ?? null,
            'verificada_en' => date('Y-m-d H:i:s'),
            'mensaje' => 'Esta página confirma que la credencial está activa al momento de consulta.',
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isValidRecord(array $record): bool
    {
        return (int) ($record['token_activo'] ?? 0) === 1
            && empty($record['token_revocado_en'])
            && $this->notExpired($record['token_expira_en'] ?? null)
            && (string) ($record['credencial_estatus'] ?? '') === 'VIGENTE'
            && empty($record['credencial_revocada_en'])
            && $this->notExpired($record['credencial_expira_en'] ?? null)
            && (int) ($record['usuario_activo'] ?? 0) === 1
            && empty($record['usuario_eliminado_en']);
    }

    private function notExpired(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return strtotime((string) $value) > time();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicVerificationByHash(string $tokenHash): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                t.id AS token_id,
                t.activo AS token_activo,
                t.creado_en AS token_creado_en,
                t.expira_en AS token_expira_en,
                t.revocado_en AS token_revocado_en,
                c.id AS credencial_id,
                c.estatus AS credencial_estatus,
                c.emitida_en AS credencial_emitida_en,
                c.expira_en AS credencial_expira_en,
                c.revocada_en AS credencial_revocada_en,
                u.id AS usuario_id,
                u.username,
                u.activo AS usuario_activo,
                u.eliminado_en AS usuario_eliminado_en,
                p.primer_nombre,
                p.segundo_nombre,
                p.apellido_paterno,
                p.apellido_materno,
                p.puesto,
                p.ubicacion_publica
            FROM credencial_tokens t
            INNER JOIN credenciales_usuario c
                ON c.id = t.credencial_id
            INNER JOIN usuarios u
                ON u.id = c.usuario_id
            LEFT JOIN perfiles_usuario p
                ON p.usuario_id = u.id
            WHERE t.token_hash = :token_hash
            LIMIT 1
            SQL
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);

        return $record === false ? null : $record;
    }
}
