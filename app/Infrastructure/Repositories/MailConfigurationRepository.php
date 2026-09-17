<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class MailConfigurationRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT * FROM mail_accounts ORDER BY activo DESC, codigo ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function primaryAccount(): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM mail_accounts WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => 'TICKETS_PRODUCTOS']);
        $account = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($account) ? $account : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rules(): array
    {
        $statement = $this->connection->pdo()->query(
            <<<'SQL'
            SELECT r.*, a.codigo AS mail_account_codigo, a.nombre AS mail_account_nombre
            FROM tickets_productos_correo_reglas r
            INNER JOIN mail_accounts a ON a.id = r.mail_account_id
            ORDER BY FIELD(
                r.evento,
                'TICKET_CREADO',
                'PARTIDA_APROBADA',
                'PARTIDA_RECHAZADA',
                'TICKET_RESUELTO_TOTAL',
                'TICKET_RESUELTO_PARCIAL',
                'TICKET_CANCELADO'
            )
            SQL
        );

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function savePrimaryAccount(array $data): int
    {
        $pdo = $this->connection->pdo();
        $existing = $this->primaryAccount();

        if ($existing === null) {
            $statement = $pdo->prepare(
                <<<'SQL'
                INSERT INTO mail_accounts (
                    codigo,
                    nombre,
                    from_email,
                    from_name,
                    reply_to_email,
                    smtp_host,
                    smtp_port,
                    smtp_encryption,
                    smtp_username,
                    smtp_secret_ref,
                    activo
                ) VALUES (
                    'TICKETS_PRODUCTOS',
                    :nombre,
                    :from_email,
                    :from_name,
                    :reply_to_email,
                    :smtp_host,
                    :smtp_port,
                    :smtp_encryption,
                    :smtp_username,
                    :smtp_secret_ref,
                    :activo
                )
                SQL
            );
            $statement->execute($data);

            return (int) $pdo->lastInsertId();
        }

        $statement = $pdo->prepare(
            <<<'SQL'
            UPDATE mail_accounts
            SET nombre = :nombre,
                from_email = :from_email,
                from_name = :from_name,
                reply_to_email = :reply_to_email,
                smtp_host = :smtp_host,
                smtp_port = :smtp_port,
                smtp_encryption = :smtp_encryption,
                smtp_username = :smtp_username,
                smtp_secret_ref = :smtp_secret_ref,
                activo = :activo
            WHERE id = :id
            SQL
        );
        $statement->execute(['id' => $existing['id']] + $data);

        return (int) $existing['id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveRule(string $event, array $data): void
    {
        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO tickets_productos_correo_reglas (
                evento,
                mail_account_id,
                enviar_solicitante,
                enviar_responsables,
                to_json,
                cc_json,
                bcc_json,
                activo
            ) VALUES (
                :evento,
                :mail_account_id,
                :enviar_solicitante,
                :enviar_responsables,
                :to_json,
                :cc_json,
                :bcc_json,
                :activo
            )
            ON DUPLICATE KEY UPDATE
                mail_account_id = VALUES(mail_account_id),
                enviar_solicitante = VALUES(enviar_solicitante),
                enviar_responsables = VALUES(enviar_responsables),
                to_json = VALUES(to_json),
                cc_json = VALUES(cc_json),
                bcc_json = VALUES(bcc_json),
                activo = VALUES(activo)
            SQL
        );
        $statement->execute(['evento' => $event] + $data);
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
