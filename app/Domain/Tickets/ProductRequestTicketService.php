<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use PDOException;
use Throwable;

final class ProductRequestTicketService
{
    private const MAX_PARTIDAS = 50;
    private const TICKET_EN_REVISION = 'EN_REVISION';
    private const TICKET_RESUELTO_PARCIAL = 'RESUELTO_PARCIAL';
    private const TICKET_APROBADO = 'APROBADO';
    private const TICKET_RECHAZADO = 'RECHAZADO';
    private const TICKET_CANCELADO = 'CANCELADO';
    private const PARTIDA_EN_REVISION = 'EN_REVISION';
    private const PARTIDA_APROBADA = 'APROBADA';
    private const PARTIDA_RECHAZADA = 'RECHAZADA';

    public function __construct(
        private readonly ProductRequestTicketRepository $tickets
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function crearTicket(array $input, int $usuarioId): array
    {
        $request = $this->validateTicketInput($input, $usuarioId);

        return $this->transactional(function () use ($request): array {
            $this->assertActiveUser($request['solicitante_usuario_id']);
            $this->assertCompany($request['empresa_id']);
            $this->assertWarehouseScope(
                $request['empresa_id'],
                $request['almacen_id']
            );

            $folio = $this->tickets->emitProductTicketFolio(
                $request['empresa_id'],
                $request['almacen_id'],
                $request['solicitante_usuario_id']
            );

            $ticketId = $this->tickets->createTicket([
                'folio' => $folio['folio'],
                'empresa_id' => $request['empresa_id'],
                'almacen_id' => $request['almacen_id'],
                'solicitante_usuario_id' => $request['solicitante_usuario_id'],
                'estado' => self::TICKET_EN_REVISION,
                'observaciones_generales' => $request['observaciones_generales'],
                'total_partidas' => count($request['partidas']),
                'partidas_en_revision' => count($request['partidas']),
                'partidas_aprobadas' => 0,
                'partidas_rechazadas' => 0,
            ]);

            $this->tickets->insertEvent(
                $ticketId,
                null,
                $request['solicitante_usuario_id'],
                'TICKET_CREADO',
                'Ticket documental de solicitud de alta de productos creado.',
                ['folio' => $folio['folio']]
            );

            foreach ($request['partidas'] as $index => $partida) {
                $partidaId = $this->tickets->createPartida(
                    $ticketId,
                    $index + 1,
                    $partida
                );
                $this->tickets->insertEvent(
                    $ticketId,
                    $partidaId,
                    $request['solicitante_usuario_id'],
                    'PARTIDA_AGREGADA',
                    'Partida documental agregada al ticket.',
                    ['numero_partida' => $index + 1]
                );
            }

            return $this->obtenerTicketOrFail($ticketId);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function resolverPartida(
        int $ticketId,
        int $partidaId,
        string $accion,
        array $input,
        int $usuarioId
    ): array {
        $ticketId = $this->positiveIdValue($ticketId, 'ticket_id');
        $partidaId = $this->positiveIdValue($partidaId, 'partida_id');
        $usuarioId = $this->positiveIdValue($usuarioId, 'usuario_id');
        $accion = strtoupper(trim($accion));

        if (!in_array($accion, ['APROBAR', 'RECHAZAR'], true)) {
            throw new ProductRequestTicketValidationException([
                'accion' => 'La acción debe ser APROBAR o RECHAZAR.',
            ]);
        }

        $comment = $this->nullableText($input, 'comentario_resolucion', 1000);
        $rejectReason = $this->nullableText($input, 'motivo_rechazo', 1000);

        if ($comment === false) {
            throw new ProductRequestTicketValidationException([
                'comentario_resolucion' =>
                    'El comentario admite hasta 1000 caracteres.',
            ]);
        }

        if ($rejectReason === false) {
            throw new ProductRequestTicketValidationException([
                'motivo_rechazo' => 'El motivo admite hasta 1000 caracteres.',
            ]);
        }

        if ($accion === 'RECHAZAR' && $rejectReason === null) {
            throw new ProductRequestTicketValidationException([
                'motivo_rechazo' => 'El motivo de rechazo es obligatorio.',
            ]);
        }

        return $this->transactional(function () use (
            $ticketId,
            $partidaId,
            $accion,
            $comment,
            $rejectReason,
            $usuarioId
        ): array {
            $this->assertActiveUser($usuarioId);
            $ticket = $this->assertTicketForUpdate($ticketId);
            $partida = $this->assertPartidaForUpdate($ticketId, $partidaId);

            if ((string) $ticket['estado'] === self::TICKET_CANCELADO) {
                throw new ProductRequestTicketValidationException([
                    'ticket_id' => 'No se puede resolver un ticket cancelado.',
                ]);
            }

            if ((string) $partida['estado'] !== self::PARTIDA_EN_REVISION) {
                throw new ProductRequestTicketValidationException([
                    'partida_id' => 'La partida ya fue resuelta.',
                ]);
            }

            $newState = $accion === 'APROBAR'
                ? self::PARTIDA_APROBADA
                : self::PARTIDA_RECHAZADA;
            $event = $accion === 'APROBAR'
                ? 'PARTIDA_APROBADA'
                : 'PARTIDA_RECHAZADA';

            $this->tickets->updatePartidaResolution(
                $partidaId,
                $newState,
                $usuarioId,
                $comment,
                $accion === 'APROBAR' ? null : $rejectReason
            );
            $this->recalculateTicket($ticketId);
            $this->tickets->insertEvent(
                $ticketId,
                $partidaId,
                $usuarioId,
                $event,
                $accion === 'APROBAR'
                    ? 'Partida aprobada documentalmente.'
                    : 'Partida rechazada documentalmente.',
                [
                    'estado' => $newState,
                    'motivo_rechazo' => $accion === 'APROBAR'
                        ? null
                        : $rejectReason,
                ]
            );

            return $this->obtenerTicketOrFail($ticketId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelarTicket(
        int $ticketId,
        string $motivo,
        int $usuarioId
    ): array {
        $ticketId = $this->positiveIdValue($ticketId, 'ticket_id');
        $usuarioId = $this->positiveIdValue($usuarioId, 'usuario_id');
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new ProductRequestTicketValidationException([
                'motivo' => 'El motivo de cancelación es obligatorio.',
            ]);
        }
        if ($this->length($motivo) > 1000) {
            throw new ProductRequestTicketValidationException([
                'motivo' => 'El motivo admite hasta 1000 caracteres.',
            ]);
        }

        return $this->transactional(function () use (
            $ticketId,
            $motivo,
            $usuarioId
        ): array {
            $this->assertActiveUser($usuarioId);
            $ticket = $this->assertTicketForUpdate($ticketId);

            if ((string) $ticket['estado'] === self::TICKET_CANCELADO) {
                throw new ProductRequestTicketValidationException([
                    'ticket_id' => 'El ticket ya está cancelado.',
                ]);
            }

            $this->tickets->cancelTicket($ticketId, $motivo, $usuarioId);
            $this->tickets->insertEvent(
                $ticketId,
                null,
                $usuarioId,
                'TICKET_CANCELADO',
                'Ticket documental cancelado.',
                ['motivo' => $motivo]
            );

            return $this->obtenerTicketOrFail($ticketId);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerTicket(int $ticketId): ?array
    {
        $ticketId = $this->positiveIdValue($ticketId, 'ticket_id');
        $ticket = $this->tickets->findTicketById($ticketId);

        if ($ticket === null) {
            return null;
        }

        return $this->normalizeTicket($ticketId, $ticket);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = $this->tickets->beginTransaction();

        try {
            $result = $operation();
            $this->tickets->commit($ownsTransaction);

            return $result;
        } catch (ProductRequestTicketValidationException $exception) {
            $this->tickets->rollBack($ownsTransaction);
            throw $exception;
        } catch (PDOException) {
            $this->tickets->rollBack($ownsTransaction);
            throw new ProductRequestTicketValidationException([
                'database' => 'No fue posible procesar el ticket documental.',
            ]);
        } catch (Throwable $exception) {
            $this->tickets->rollBack($ownsTransaction);
            throw $exception;
        }
    }

    private function assertActiveUser(int $userId): void
    {
        if (!$this->tickets->activeUserExists($userId)) {
            throw new ProductRequestTicketValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }
    }

    private function assertCompany(int $companyId): void
    {
        if (!$this->tickets->activeCompanyExists($companyId)) {
            throw new ProductRequestTicketValidationException([
                'empresa_id' => 'La empresa no existe o no está activa.',
            ]);
        }
    }

    private function assertWarehouseScope(int $companyId, int $warehouseId): void
    {
        if (!$this->tickets->warehouseBelongsToCompany($companyId, $warehouseId)) {
            throw new ProductRequestTicketValidationException([
                'almacen_id' =>
                    'El almacén no existe, no está activo o no pertenece a la empresa.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function assertTicketForUpdate(int $ticketId): array
    {
        $ticket = $this->tickets->findTicketByIdForUpdate($ticketId);

        if ($ticket === null) {
            throw new ProductRequestTicketValidationException([
                'ticket_id' => 'El ticket no existe.',
            ]);
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertPartidaForUpdate(int $ticketId, int $partidaId): array
    {
        $partida = $this->tickets->findPartidaByIdForUpdate($partidaId);

        if ($partida === null || (int) $partida['ticket_producto_id'] !== $ticketId) {
            throw new ProductRequestTicketValidationException([
                'partida_id' => 'La partida no existe o no pertenece al ticket.',
            ]);
        }

        return $partida;
    }

    private function recalculateTicket(int $ticketId): void
    {
        $counts = $this->tickets->countPartidasByState($ticketId);
        $state = $this->ticketStateFromCounts($counts);
        $this->tickets->updateTicketCounters($ticketId, $state, $counts);
    }

    /**
     * @param array{total: int, en_revision: int, aprobadas: int, rechazadas: int} $counts
     */
    private function ticketStateFromCounts(array $counts): string
    {
        if ($counts['en_revision'] > 0) {
            return self::TICKET_EN_REVISION;
        }
        if ($counts['total'] > 0 && $counts['aprobadas'] === $counts['total']) {
            return self::TICKET_APROBADO;
        }
        if ($counts['total'] > 0 && $counts['rechazadas'] === $counts['total']) {
            return self::TICKET_RECHAZADO;
        }

        return self::TICKET_RESUELTO_PARCIAL;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     empresa_id: int,
     *     almacen_id: int,
     *     solicitante_usuario_id: int,
     *     observaciones_generales: string|null,
     *     partidas: list<array<string, mixed>>
     * }
     */
    private function validateTicketInput(array $input, int $usuarioId): array
    {
        $errors = [];
        $companyId = $this->positiveId($input['empresa_id'] ?? null);
        $warehouseId = $this->positiveId($input['almacen_id'] ?? null);
        $requesterId = $this->positiveId($input['solicitante_usuario_id'] ?? $usuarioId);
        $notes = $this->nullableText($input, 'observaciones_generales', 2000);

        if ($companyId === null) {
            $errors['empresa_id'] = 'La empresa es obligatoria.';
        }
        if ($warehouseId === null) {
            $errors['almacen_id'] = 'El almacén es obligatorio.';
        }
        if ($requesterId === null) {
            $errors['solicitante_usuario_id'] =
                'El solicitante es obligatorio.';
        }
        if ($notes === false) {
            $errors['observaciones_generales'] =
                'Las observaciones admiten hasta 2000 caracteres.';
            $notes = null;
        }

        $rawParts = $input['partidas'] ?? null;
        $parts = [];

        if (!is_array($rawParts) || $rawParts === []) {
            $errors['partidas'] = 'Agrega al menos una partida.';
        } elseif (count($rawParts) > self::MAX_PARTIDAS) {
            $errors['partidas'] = 'El ticket admite máximo 50 partidas.';
        } else {
            foreach (array_values($rawParts) as $index => $rawPart) {
                if (!is_array($rawPart)) {
                    $errors['partidas.' . $index] = 'La partida no es válida.';
                    continue;
                }

                $part = $this->validatePartida($rawPart, $index, $errors);

                if ($part !== null) {
                    $parts[] = $part;
                }
            }
        }

        if ($errors !== []) {
            throw new ProductRequestTicketValidationException($errors);
        }

        return [
            'empresa_id' => $companyId ?? 0,
            'almacen_id' => $warehouseId ?? 0,
            'solicitante_usuario_id' => $requesterId ?? 0,
            'observaciones_generales' => $notes,
            'partidas' => $parts,
        ];
    }

    /**
     * @param array<string, mixed> $rawPart
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private function validatePartida(
        array $rawPart,
        int $index,
        array &$errors
    ): ?array {
        $description = trim((string) ($rawPart['descripcion'] ?? ''));
        $model = $this->nullableText($rawPart, 'modelo', 120);
        $brand = $this->nullableText($rawPart, 'marca_texto', 120);
        $providerText = $this->nullableText($rawPart, 'proveedor_texto', 180);
        $notes = $this->nullableText($rawPart, 'observaciones', 1000);
        $providerId = $this->optionalPositiveId($rawPart['proveedor_id'] ?? null);
        $unitSatId = $this->optionalPositiveId($rawPart['unidad_sat_id'] ?? null);
        $satKeyId = $this->optionalPositiveId($rawPart['clave_sat_id'] ?? null);
        $currencyId = $this->optionalPositiveId($rawPart['moneda_id'] ?? null);
        $suggestedCost = $this->optionalDecimal($rawPart['costo_sugerido'] ?? null);
        $weight = $this->optionalDecimal($rawPart['peso'] ?? null);
        $tracksSeries = $this->boolValue($rawPart['lleva_serie'] ?? false);
        $prefix = 'partidas.' . $index . '.';

        if ($description === '') {
            $errors[$prefix . 'descripcion'] = 'La descripción es obligatoria.';
        } elseif ($this->length($description) > 2000) {
            $errors[$prefix . 'descripcion'] =
                'La descripción admite hasta 2000 caracteres.';
        }

        foreach ([
            'modelo' => $model,
            'marca_texto' => $brand,
            'proveedor_texto' => $providerText,
            'observaciones' => $notes,
        ] as $field => $value) {
            if ($value === false) {
                $errors[$prefix . $field] = 'El texto excede la longitud permitida.';
            }
        }

        foreach ([
            'proveedor_id' => $providerId,
            'unidad_sat_id' => $unitSatId,
            'clave_sat_id' => $satKeyId,
            'moneda_id' => $currencyId,
        ] as $field => $value) {
            if ($value === false) {
                $errors[$prefix . $field] =
                    'El identificador debe ser entero positivo.';
            }
        }

        if ($suggestedCost === false) {
            $errors[$prefix . 'costo_sugerido'] =
                'El costo sugerido debe ser decimal mayor o igual a cero.';
        }
        if ($weight === false) {
            $errors[$prefix . 'peso'] =
                'El peso debe ser decimal mayor o igual a cero.';
        }

        if (isset($errors[$prefix . 'descripcion'])) {
            return null;
        }

        return [
            'estado' => self::PARTIDA_EN_REVISION,
            'modelo' => $model === false ? null : $model,
            'marca_texto' => $brand === false ? null : $brand,
            'descripcion' => $description,
            'proveedor_id' => $providerId === false ? null : $providerId,
            'proveedor_texto' => $providerText === false ? null : $providerText,
            'unidad_sat_id' => $unitSatId === false ? null : $unitSatId,
            'clave_sat_id' => $satKeyId === false ? null : $satKeyId,
            'moneda_id' => $currencyId === false ? null : $currencyId,
            'costo_sugerido' => $suggestedCost === false ? null : $suggestedCost,
            'peso' => $weight === false ? null : $weight,
            'lleva_serie' => $tracksSeries ? 1 : 0,
            'observaciones' => $notes === false ? null : $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function obtenerTicketOrFail(int $ticketId): array
    {
        $ticket = $this->obtenerTicket($ticketId);

        if ($ticket === null) {
            throw new \RuntimeException('Ticket documental no encontrado.');
        }

        return $ticket;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>
     */
    private function normalizeTicket(int $ticketId, array $ticket): array
    {
        return $ticket + [
            'partidas' => $this->tickets->listPartidas($ticketId),
            'comentarios' => $this->tickets->listComentarios($ticketId),
            'adjuntos' => array_map(
                fn (array $attachment): array => $this->normalizeAttachment($attachment),
                $this->tickets->listAdjuntos($ticketId)
            ),
            'eventos' => $this->tickets->listEventos($ticketId),
        ];
    }

    /**
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    private function normalizeAttachment(array $attachment): array
    {
        unset($attachment['ruta_absoluta'], $attachment['absolute_path']);

        return $attachment;
    }

    private function positiveId(mixed $value): ?int
    {
        if (
            (!is_string($value) && !is_int($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
        ) {
            return null;
        }

        return (int) $value;
    }

    private function positiveIdValue(int $value, string $field): int
    {
        if ($value < 1) {
            throw new ProductRequestTicketValidationException([
                $field => 'El identificador debe ser entero positivo.',
            ]);
        }

        return $value;
    }

    private function optionalPositiveId(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value) ?? false;
    }

    private function optionalDecimal(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $value = trim((string) $value);

        if (
            preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/', $value) !== 1
        ) {
            return false;
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $integer . '.' . str_pad($fraction, 6, '0');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableText(
        array $input,
        string $key,
        int $max
    ): string|null|false {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            return null;
        }

        return $this->length($value) <= $max ? $value : false;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'si', 'sí'], true);
        }

        return false;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
