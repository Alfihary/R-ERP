<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\N8nProductSearchRepository;
use JsonException;

final class N8nProductSearchController
{
    public function __construct(
        private readonly N8nProductSearchRepository $products,
        private readonly string $apiSecret
    ) {
    }

    public function search(Request $request): Response
    {
        $providedToken = $request->header('X-N8N-Token');
        if (
            $this->apiSecret === ''
            || $providedToken === null
            || $providedToken === ''
            || !hash_equals($this->apiSecret, $providedToken)
        ) {
            return Response::json(['ok' => false, 'error' => 'No autorizado.'], 401);
        }

        try {
            $rawBody = $request->rawBody(16384);
        } catch (\LengthException) {
            return Response::json(['ok' => false, 'error' => 'Solicitud demasiado grande.'], 413);
        }

        try {
            $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json(['ok' => false, 'error' => 'JSON inválido.'], 400);
        }
        if (!is_array($input) || array_is_list($input)) {
            return Response::json(['ok' => false, 'error' => 'Se esperaba un objeto JSON.'], 400);
        }

        $allowedKeys = ['consulta', 'modelo', 'cantidad'];
        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            return Response::json(['ok' => false, 'error' => 'El JSON contiene claves no permitidas.'], 422);
        }

        $query = $input['consulta'] ?? '';
        $model = $input['modelo'] ?? '';
        $quantity = $input['cantidad'] ?? 1;
        if (
            !is_string($query)
            || !is_string($model)
            || strlen($query) > 1000
            || strlen($model) > 200
            || !is_int($quantity)
            || $quantity < 1
            || $quantity > 100000
        ) {
            return Response::json(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
        }

        $query = trim($query);
        $model = trim($model);
        if ($query === '' && $model === '') {
            return Response::json(['ok' => false, 'error' => 'Indica consulta o modelo.'], 422);
        }

        $terms = array_values(array_unique(array_filter([
            ...($model !== '' ? [$model] : []),
            ...preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY),
        ], static fn (string $term): bool => strlen($term) >= 3)));
        $normalizedCodes = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => strtoupper(preg_replace('/[-\s]+/u', '', trim($value)) ?? ''),
            $terms
        ))));

        $matches = $this->products->byNormalizedCode($normalizedCodes);
        $type = 'exacta_codigo';
        $reason = 'Código de producto exacto';
        $score = 100;

        if ($matches === []) {
            $matches = $this->products->byModel($normalizedCodes);
            $type = 'exacta_modelo';
            $reason = 'Modelo exacto';
            $score = 98;
        }
        if ($matches === []) {
            $matches = $this->products->byPartNumber($normalizedCodes);
            $type = 'exacta_numero_parte';
            $reason = 'Número de parte exacto';
            $score = 96;
        }
        if ($matches === []) {
            $matches = $this->products->byManufacturerSku($normalizedCodes);
            $type = 'exacta_sku_fabricante';
            $reason = 'SKU de fabricante exacto';
            $score = 94;
        }
        if ($matches === [] && $query !== '') {
            $matches = $this->products->byExactDescription($query);
            $type = 'exacta_descripcion';
            $reason = 'Descripción exacta';
            $score = 92;
        }
        if ($matches === [] && $query !== '') {
            $matches = $this->products->byExactDescription($query, true);
            $type = 'exacta_descripcion_larga';
            $reason = 'Descripción larga exacta';
            $score = 90;
        }
        if ($matches === []) {
            $matches = $this->products->partial([$query, $model]);
            $type = 'parcial';
            $reason = 'Coincidencia parcial de catálogo';
            $score = 60;
        }

        if ($matches === []) {
            return Response::json(['ok' => true, 'encontrado' => false, 'resultados' => []]);
        }

        $results = array_map(static fn (array $product): array => [
            'id' => (int) $product['id'],
            'id_producto' => (string) $product['id_producto'],
            'descripcion' => (string) $product['descripcion'],
            'descripcion_larga' => $product['descripcion_larga'] === null
                ? null : (string) $product['descripcion_larga'],
            'marca' => $product['marca'] === null ? null : (string) $product['marca'],
            'unidad' => $product['unidad'] === null ? null : (string) $product['unidad'],
            'unidad_abreviatura' => $product['unidad_abreviatura'] === null
                ? null : (string) $product['unidad_abreviatura'],
            'match_score' => $score,
            'motivo' => $reason,
        ], $matches);

        return Response::json([
            'ok' => true,
            'encontrado' => true,
            'tipo_coincidencia' => $type,
            'resultados' => $results,
        ]);
    }
}
