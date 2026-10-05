<?php

declare(strict_types=1);

use App\Domain\Security\PermissionService;
use App\Domain\Scope\UserScopeService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;

return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ($expectedDatabase !== CorreoOutboxElegiblesCancellation::DATABASE
            || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase
        ) {
            throw new RuntimeException('Unexpected active database for cancellation DB-TEST.');
        }

        $connection = $GLOBALS['correo_outbox_elegibles_cancelacion_connection'] ?? null;
        if (!$connection instanceof App\Infrastructure\Database\ConnectionProvider) {
            throw new RuntimeException('Cancellation DB-TEST connection is unavailable.');
        }
        $runner = new CorreoOutboxElegiblesCancellation(
            $connection,
            new PermissionService(new PermissionRepository($connection)),
            new UserScopeService(new ScopeRepository($connection))
        );

        $cases = [];
        $originalBefore = $this->originalSnapshot($pdo);
        $cases['original_rows_are_cancelled'] = $this->originalRowsAreCancelled($pdo);
        $fixtureIds = [];
        $pdo->beginTransaction();

        try {
            [$fixtureIds, $expected] = $this->insertFixtures($pdo);
            $rows = $this->rows($pdo, $fixtureIds);
            $runner->assertPreconditions($rows, $expected);
            $cases['exact_preconditions_pass'] = true;

            foreach ([
                'wrong_id' => static function (array &$copy): void {
                    $copy[0]['id'] = 999999;
                },
                'wrong_status' => static function (array &$copy): void {
                    $copy[0]['status'] = 'ERROR';
                },
                'wrong_attempts' => static function (array &$copy): void {
                    $copy[0]['intentos'] = 1;
                },
                'wrong_ticket' => static function (array &$copy): void {
                    $copy[0]['ticket_id'] = 34;
                },
                'wrong_event' => static function (array &$copy): void {
                    $copy[0]['evento'] = 'TICKET_CREADO';
                },
                'wrong_dedupe' => static function (array &$copy): void {
                    $copy[0]['dedupe_key'] .= ':changed';
                },
            ] as $name => $mutate) {
                $copy = $rows;
                $mutate($copy);
                $cases[$name . '_rejected'] = $this->isRejected(
                    static fn () => $runner->assertPreconditions($copy, $expected)
                );
            }

            $protectedBefore = $this->protectedSnapshot($pdo, $fixtureIds);
            $auditBefore = $this->auditCounts($pdo, $fixtureIds);
            $cases['second_failure_rolls_back_all'] = $this->atomicFailure(
                $pdo,
                $runner,
                $expected,
                $fixtureIds,
                1
            );
            $cases['third_failure_rolls_back_all'] = $this->atomicFailure(
                $pdo,
                $runner,
                $expected,
                $fixtureIds,
                2
            );

            $result = $runner->executeBatch($expected);
            $cancelled = $this->rows($pdo, $fixtureIds);
            $cases['three_cancelled_atomically'] = $result['cancelled_count'] === 3
                && array_reduce(
                    $cancelled,
                    static fn (bool $valid, array $row): bool => $valid
                        && $row['status'] === 'CANCELADO'
                        && (int) $row['intentos'] === 0
                        && $row['ultimo_intento_at'] === null
                        && $row['enviado_at'] === null
                        && $row['cancelado_at'] !== null,
                    true
                );
            $cases['protected_fields_preserved'] = $protectedBefore
                === $this->protectedSnapshot($pdo, $fixtureIds);
            $auditAfter = $this->auditCounts($pdo, $fixtureIds);
            $cases['three_required_audits'] = array_reduce(
                $fixtureIds,
                static fn (bool $valid, int $id): bool => $valid
                    && $auditAfter[$id] === $auditBefore[$id] + 1,
                true
            ) && $this->auditReasonIsExact($pdo, $fixtureIds);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $cases['rollback_cleanup_and_originals_unchanged'] = $originalBefore
            === $this->originalSnapshot($pdo)
            && $this->fixturesAbsent($pdo, $fixtureIds);
        $source = file_get_contents(BASE_PATH . '/database/correo-outbox-elegibles-cancelacion.php');
        $cases['no_smtp_or_secret_runtime'] = is_string($source)
            && !str_contains($source, 'PHPMailer')
            && !str_contains($source, 'MailTransport')
            && !str_contains($source, 'Env::get')
            && !str_contains($source, '->retry(');

        if (count($cases) !== 15 || in_array(false, $cases, true)) {
            throw new RuntimeException(
                'Cancellation DB-TEST assertions failed: '
                . json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $cases,
            'case_count' => count($cases),
            'atomicity' => '3/3_or_0/3',
            'cleanup' => 'transaction_rolled_back',
            'original_rows_698_699_700_unchanged' => true,
            'smtp_connections' => 0,
            'emails_sent' => 0,
            'secret_resolutions' => 0,
        ];
    }

    /** @return array{0:list<int>,1:array<int,array<string,int|string|null>>} */
    private function insertFixtures(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO tickets_productos_correos (
                ticket_id, partida_id, evento, plantilla, destinatario_email,
                cc_json, subject, html, text, status, intentos, max_intentos,
                error_mensaje_seguro, ultimo_intento_at, enviado_at, cancelado_at,
                creado_por_usuario_id, dedupe_key
            )
            SELECT
                ticket_id, partida_id, evento, plantilla, destinatario_email,
                cc_json, subject, html, text, 'PENDIENTE', 0, max_intentos,
                NULL, NULL, NULL, NULL, creado_por_usuario_id, :dedupe
            FROM tickets_productos_correos
            WHERE id = :source_id
            SQL
        );
        $fixtureIds = [];
        $expected = [];
        $nonce = bin2hex(random_bytes(6));

        foreach (CorreoOutboxElegiblesCancellation::EXPECTED as $sourceId => $contract) {
            $dedupe = 'qa:outbox-cancel:' . $nonce . ':' . $sourceId;
            $statement->execute(['dedupe' => $dedupe, 'source_id' => $sourceId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Unable to create controlled cancellation fixture.');
            }
            $fixtureId = (int) $pdo->lastInsertId();
            $fixtureIds[] = $fixtureId;
            $expected[$fixtureId] = $contract;
            $expected[$fixtureId]['dedupe_key'] = $dedupe;
        }

        return [$fixtureIds, $expected];
    }

    /**
     * @param array<int,array<string,int|string|null>> $expected
     * @param list<int> $fixtureIds
     */
    private function atomicFailure(
        PDO $pdo,
        CorreoOutboxElegiblesCancellation $runner,
        array $expected,
        array $fixtureIds,
        int $failureIndex
    ): bool {
        $failed = false;
        try {
            $runner->executeBatch(
                $expected,
                static function (int $index, int $id, PDO $pdo) use ($failureIndex): void {
                    if ($index !== $failureIndex) {
                        return;
                    }
                    $statement = $pdo->prepare(
                        "UPDATE tickets_productos_correos SET status = 'ENVIANDO' WHERE id = :id"
                    );
                    $statement->execute(['id' => $id]);
                }
            );
        } catch (Throwable) {
            $failed = true;
        }

        return $failed && $this->allPending($pdo, $fixtureIds);
    }

    private function isRejected(callable $operation): bool
    {
        try {
            $operation();
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function rows(PDO $pdo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            "SELECT o.*, t.folio AS ticket_folio, t.estado AS ticket_status,
                    t.almacen_id, p.estado AS partida_status
             FROM tickets_productos_correos o
             INNER JOIN tickets_productos t ON t.id = o.ticket_id
             LEFT JOIN tickets_productos_partidas p ON p.id = o.partida_id
             WHERE o.id IN ({$placeholders})
             ORDER BY o.id"
        );
        $statement->execute($ids);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int> $ids */
    private function allPending(PDO $pdo, array $ids): bool
    {
        $rows = $this->rows($pdo, $ids);

        return count($rows) === count($ids) && array_reduce(
            $rows,
            static fn (bool $valid, array $row): bool => $valid
                && $row['status'] === 'PENDIENTE'
                && (int) $row['intentos'] === 0
                && $row['ultimo_intento_at'] === null
                && $row['enviado_at'] === null
                && $row['cancelado_at'] === null,
            true
        );
    }

    private function originalRowsAreCancelled(PDO $pdo): bool
    {
        $rows = $this->rows($pdo, [698, 699, 700]);

        return count($rows) === 3 && array_reduce(
            $rows,
            static fn (bool $valid, array $row): bool => $valid
                && $row['status'] === 'CANCELADO'
                && (int) $row['intentos'] === 0
                && $row['ultimo_intento_at'] === null
                && $row['enviado_at'] === null
                && $row['cancelado_at'] !== null,
            true
        );
    }

    private function originalSnapshot(PDO $pdo): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->rows($pdo, [698, 699, 700]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    /** @param list<int> $ids @return array<int,string> */
    private function protectedSnapshot(PDO $pdo, array $ids): array
    {
        $snapshot = [];
        foreach ($this->rows($pdo, $ids) as $row) {
            $protected = [
                $row['ticket_id'], $row['partida_id'], $row['evento'], $row['plantilla'],
                $row['destinatario_email'], $row['cc_json'], $row['subject'], $row['html'],
                $row['text'], $row['intentos'], $row['max_intentos'], $row['error_mensaje_seguro'],
                $row['ultimo_intento_at'], $row['enviado_at'], $row['creado_por_usuario_id'],
                $row['dedupe_key'],
            ];
            $snapshot[(int) $row['id']] = hash(
                'sha256',
                json_encode($protected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        }

        return $snapshot;
    }

    /** @param list<int> $ids @return array<int,int> */
    private function auditCounts(PDO $pdo, array $ids): array
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM auditoria_eventos
             WHERE accion = 'MAIL_OUTBOX_CANCELLED'
               AND entidad = 'tickets_productos_correos'
               AND entidad_id = :id"
        );
        $counts = [];
        foreach ($ids as $id) {
            $statement->execute(['id' => (string) $id]);
            $counts[$id] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    /** @param list<int> $ids */
    private function auditReasonIsExact(PDO $pdo, array $ids): bool
    {
        $statement = $pdo->prepare(
            "SELECT metadata_json FROM auditoria_eventos
             WHERE accion = 'MAIL_OUTBOX_CANCELLED'
               AND entidad = 'tickets_productos_correos'
               AND entidad_id = :id
             ORDER BY id DESC LIMIT 1"
        );
        foreach ($ids as $id) {
            $statement->execute(['id' => (string) $id]);
            $metadata = json_decode((string) $statement->fetchColumn(), true);
            if (!is_array($metadata)
                || ($metadata['motivo'] ?? null) !== CorreoOutboxElegiblesCancellation::REASON
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $ids */
    private function fixturesAbsent(PDO $pdo, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM tickets_productos_correos WHERE id IN ({$placeholders})"
        );
        $statement->execute($ids);

        return (int) $statement->fetchColumn() === 0;
    }
};
