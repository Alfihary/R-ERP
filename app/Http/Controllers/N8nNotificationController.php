<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Notifications\NotificationService;
use App\Domain\Solicitudes\SolicitudCotizacionService;
use JsonException;
use PDOException;

final class N8nNotificationController
{
    private const TYPES = [
        'solicitud_cotizacion', 'solicitud_vcard', 'cotizacion_pendiente',
        'cotizacion_enviada', 'seguimiento_pendiente', 'cliente_respondio',
        'oportunidad_por_vencer', 'recordatorio_llamada', 'solicitud_capacitacion',
    ];
    private const ENTITY_TYPES = ['solicitud', 'cotizacion', 'seguimiento', 'cliente', 'oportunidad', 'capacitacion', 'vcard'];
    private const PRIORITIES = ['baja', 'normal', 'alta', 'urgente'];
    private const RECIPIENT_ROLES = ['VENTAS', 'GERENCIA', 'ADMIN'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly string $apiSecret,
        private readonly ?SolicitudCotizacionService $solicitudes = null
    ) {
    }

    public function create(Request $request): Response
    {
        $token = $request->header('X-N8N-Token');
        if ($this->apiSecret === '' || $token === null || $token === '' || !hash_equals($this->apiSecret, $token)) {
            return Response::json(['ok' => false, 'error' => 'No autorizado.'], 401);
        }

        try {
            $raw = $request->rawBody(16384);
        } catch (\LengthException) {
            return Response::json(['ok' => false, 'error' => 'Solicitud demasiado grande.'], 413);
        }
        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));
        if ($contentType !== 'application/json') {
            return Response::json(['ok' => false, 'error' => 'Content-Type inválido.'], 415);
        }
        try {
            $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json(['ok' => false, 'error' => 'JSON inválido.'], 400);
        }
        if (!$decoded instanceof \stdClass) {
            return Response::json(['ok' => false, 'error' => 'Se esperaba un objeto JSON.'], 400);
        }
        $data = get_object_vars($decoded);
        $allowed = ['usuario_id', 'rol_codigo', 'tipo', 'titulo', 'mensaje', 'prioridad', 'entidad_tipo', 'entidad_id', 'accion_url', 'origen', 'idempotency_key', 'solicitud_id', 'folio', 'nombre', 'telefono', 'correo', 'solicitud', 'vendedor', 'estado', 'producto'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            return Response::json(['ok' => false, 'error' => 'El JSON contiene claves no permitidas.'], 422);
        }

        $required = ['usuario_id', 'rol_codigo', 'tipo', 'titulo', 'mensaje', 'idempotency_key'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                return Response::json(['ok' => false, 'error' => 'Faltan campos requeridos.'], 422);
            }
        }
        $userId = $data['usuario_id'];
        $roleCode = $data['rol_codigo'];
        $type = $data['tipo'];
        $title = $data['titulo'];
        $message = $data['mensaje'];
        $priority = $data['prioridad'] ?? 'normal';
        $entityType = $data['entidad_tipo'] ?? null;
        $entityId = $data['entidad_id'] ?? null;
        $actionUrl = $data['accion_url'] ?? null;
        $idempotencyKey = $data['idempotency_key'];
        if (
            !is_int($userId) || $userId < 1
            || !is_string($roleCode) || !in_array($roleCode, self::RECIPIENT_ROLES, true)
            || !is_string($type) || !in_array($type, self::TYPES, true)
            || !is_string($title) || ($title = trim($title)) === '' || strlen($title) > 160
            || !is_string($message) || ($message = trim($message)) === '' || strlen($message) > 2000
            || !is_string($priority) || !in_array($priority, self::PRIORITIES, true)
            || ($entityType !== null && (!is_string($entityType) || !in_array($entityType, self::ENTITY_TYPES, true)))
            || ($entityId !== null && (!is_int($entityId) || $entityId < 1))
            || (($entityType === null) !== ($entityId === null))
            || ($actionUrl !== null && (!is_string($actionUrl) || !$this->isSafeInternalUrl($actionUrl)))
            || !is_string($idempotencyKey) || preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $idempotencyKey) !== 1
            || (isset($data['origen']) && $data['origen'] !== 'n8n')
        ) {
            return Response::json(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
        }

        if ($type === 'solicitud_cotizacion') {
            if ($this->solicitudes === null || !is_string($data['solicitud_id'] ?? null)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $data['solicitud_id']) !== 1
                || !array_key_exists('producto', $data) || ($data['producto'] !== null && !is_object($data['producto']))) {
                return Response::json(['ok' => false, 'error' => 'Datos de solicitud inválidos.'], 422);
            }
            $product = $data['producto'] === null ? [] : get_object_vars($data['producto']);
            try {
                $payload = [
                    'usuario_id' => $userId, 'rol_codigo' => $roleCode, 'tipo' => $type,
                    'titulo' => $title, 'mensaje' => $message, 'prioridad' => $priority,
                    'idempotency_key' => $idempotencyKey, 'solicitud_id' => $data['solicitud_id'],
                    'nombre_cliente' => $this->nullableString($data['nombre'] ?? null, 160),
                    'telefono' => $this->nullableString($data['telefono'] ?? null, 64),
                    'correo' => $this->nullableString($data['correo'] ?? null, 190),
                    'solicitud' => $this->requiredString($data['solicitud'] ?? null, 4000),
                    'vendedor' => $this->nullableString($data['vendedor'] ?? null, 160),
                    'vendedor_usuario_id' => $this->solicitudes->resolveExactVendedorUserId(
                        $userId,
                        $this->nullableString($data['vendedor'] ?? null, 160)
                    ),
                    'estado' => $this->nullableString($data['estado'] ?? 'recibida', 32) ?: 'recibida',
                    'producto_id_externo' => $this->nullableString($product['id_externo'] ?? null, 100),
                    'producto_codigo' => $this->nullableString($product['codigo'] ?? null, 100),
                    'producto_descripcion' => $this->nullableString($product['descripcion'] ?? null, 255),
                    'producto_marca' => $this->nullableString($product['marca'] ?? null, 120),
                    'producto_unidad' => $this->nullableString($product['unidad'] ?? null, 80),
                    'match_score' => isset($product['match_score']) && is_numeric($product['match_score']) ? (float) $product['match_score'] : null,
                    'match_motivo' => $this->nullableString($product['motivo'] ?? null, 255),
                ];
            } catch (\InvalidArgumentException) {
                return Response::json(['ok' => false, 'error' => 'Datos de solicitud inválidos.'], 422);
            }
            if ($payload['nombre_cliente'] === null) {
                return Response::json(['ok' => false, 'error' => 'Nombre requerido.'], 422);
            }
            try {
                $result = $this->solicitudes->upsertAndNotify($payload, $this->notifications);
            } catch (PDOException) {
                return Response::json(['ok' => false, 'error' => 'No fue posible guardar la solicitud.'], 500);
            }
            $notification = $result['notification'];
            $duplicate = ($notification['status'] ?? '') === 'duplicate';
            return Response::json(['ok' => true, 'creada' => !$duplicate, 'duplicada' => $duplicate, 'solicitud_id' => $data['solicitud_id'], 'solicitud_db_id' => (int) $result['solicitud']['id'], 'folio' => $result['solicitud']['folio'], 'notificacion_id' => $notification['id'] ?? null], $duplicate ? 200 : 201);
        }

        try {
            $result = $this->notifications->create([
                'usuario_id' => $userId,
                'rol_codigo' => $roleCode,
                'tipo' => $type,
                'titulo' => $title,
                'mensaje' => $message,
                'prioridad' => $priority,
                'entidad_tipo' => $entityType,
                'entidad_id' => $entityId,
                'accion_url' => $actionUrl,
                'origen' => 'n8n',
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (PDOException) {
            return Response::json(['ok' => false, 'error' => 'No fue posible guardar la notificación.'], 500);
        }

        if ($result['status'] === 'invalid_recipient') {
            return Response::json(['ok' => false, 'error' => 'Usuario inexistente, inactivo o sin ese rol destinatario.'], 422);
        }
        if ($result['status'] === 'conflict') {
            return Response::json(['ok' => false, 'error' => 'La clave de idempotencia ya pertenece a otro evento.'], 409);
        }
        $duplicate = $result['status'] === 'duplicate';
        return Response::json([
            'ok' => true,
            'creada' => !$duplicate,
            'duplicada' => $duplicate,
            'notificacion_id' => $result['id'],
        ], $duplicate ? 200 : 201);
    }

    private function isSafeInternalUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $decodedUrl = rawurldecode($url);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decodedUrl) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $path = $parts['path'] ?? '';
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        $decodedPath = rawurldecode($path);
        if (str_starts_with($decodedPath, '//')) {
            return false;
        }
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new \InvalidArgumentException('Texto inválido.');
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) throw new \InvalidArgumentException('Texto inválido.');
        return $value;
    }

    private function requiredString(mixed $value, int $max): string
    {
        $value = $this->nullableString($value, $max);
        if ($value === null) throw new \InvalidArgumentException('Texto requerido.');
        return $value;
    }
}







