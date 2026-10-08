<?php

declare(strict_types=1);

namespace App\Domain\Solicitudes;

use App\Domain\Notifications\NotificationService;
use App\Infrastructure\Repositories\SolicitudCotizacionRepository;
use Throwable;

final class SolicitudCotizacionService
{
    public function __construct(private readonly SolicitudCotizacionRepository $repository)
    {
    }

    /** @param array<string,mixed> $data */
    public function upsertAndNotify(array $data, NotificationService $notifications): array
    {
        $owns = $this->repository->beginTransaction();
        try {
            $existing = $this->repository->findBySolicitudIdForUpdate($data['solicitud_id']);
            $solicitud = $existing === null
                ? $this->repository->create($data)
                : $this->repository->updateExisting((int) $existing['id'], $data);
            $notification = $notifications->create([
                'usuario_id' => $data['usuario_id'], 'rol_codigo' => $data['rol_codigo'],
                'tipo' => $data['tipo'], 'titulo' => $data['titulo'], 'mensaje' => $data['mensaje'],
                'prioridad' => $data['prioridad'], 'entidad_tipo' => 'solicitud_cotizacion',
                'entidad_id' => (int) $solicitud['id'],
                'accion_url' => '/app/solicitudes-cotizacion/' . (int) $solicitud['id'],
                'origen' => 'n8n', 'idempotency_key' => $data['idempotency_key'],
            ]);
            $this->repository->commit($owns);
            return ['solicitud' => $solicitud, 'notification' => $notification];
        } catch (Throwable $e) {
            $this->repository->rollBack($owns);
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->repository->findForUser($id, $userId);
    }

    public function resolveExactVendedorUserId(int $candidateId, ?string $vendedor): ?int
    {
        return $this->repository->resolveExactVendedorUserId($candidateId, $vendedor);
    }
}
