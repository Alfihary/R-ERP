<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Domain\Audit\AuditService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductSimilarRepository;
use PDOException;

final class ProductSimilarService
{
    public function __construct(
        private readonly ConnectionProvider $connection,
        private readonly ProductSimilarRepository $relations,
        private readonly AuditService $audit
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $origin, string $term): array
    {
        $origin = $this->normalize($origin);
        $term = trim($term);

        if ($origin === '' || $term === '') {
            return [];
        }

        return $this->relations->search($origin, mb_substr($term, 0, 80));
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $productId): array
    {
        $productId = $this->normalize($productId);
        return $productId === '' ? [] : $this->relations->listFor($productId);
    }

    public function add(string $origin, string $target, int $actorId): void
    {
        $principal = $this->normalize($origin);
        $related = $this->normalize($target);
        [$a, $b] = $this->canonical($origin, $target);
        $this->assertActive($a, 'producto_a_id');
        $this->assertActive($b, 'producto_b_id');
        $pdo = $this->connection->pdo();
        $owns = !$pdo->inTransaction();

        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $existing = $this->relations->relation($a, $b);
            if ($existing !== null && $existing['eliminado_en'] === null) {
                throw new ProductSimilarException(['target' => 'La relación ya existe.']);
            }
            if ($existing !== null) {
                $this->relations->reactivate($a, $b, $actorId);
            } else {
                $this->relations->insert($a, $b, $actorId);
            }
            $this->audit->recordRequired('productos.similares.agregar', $actorId, [
                'entidad' => 'producto_similar',
                'entidad_id' => $a . ':' . $b,
                'producto_a_id' => $a,
                'producto_b_id' => $b,
                'version_contrato' => 1,
                'modulo' => 'productos',
                'producto_id' => $principal,
                'producto_relacionado_id' => $related,
                'subrecurso' => 'similar',
            ]);
            if ($owns) {
                $pdo->commit();
            }
        } catch (ProductSimilarException $exception) {
            if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        } catch (PDOException $exception) {
            if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw new ProductSimilarException(['target' => 'No fue posible guardar la relación.'], $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
    }

    public function remove(string $origin, string $target, int $actorId): void
    {
        $principal = $this->normalize($origin);
        $related = $this->normalize($target);
        [$a, $b] = $this->canonical($origin, $target);
        $pdo = $this->connection->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) { $pdo->beginTransaction(); }
        try {
            $existing = $this->relations->relation($a, $b);
            if ($existing === null || $existing['eliminado_en'] !== null) {
                throw new ProductSimilarException(['target' => 'La relación no existe.']);
            }
            $this->relations->softDelete($a, $b, $actorId);
            $this->audit->recordRequired('productos.similares.quitar', $actorId, [
                'entidad' => 'producto_similar',
                'entidad_id' => $a . ':' . $b,
                'producto_a_id' => $a,
                'producto_b_id' => $b,
                'version_contrato' => 1,
                'modulo' => 'productos',
                'producto_id' => $principal,
                'producto_relacionado_id' => $related,
                'subrecurso' => 'similar',
            ]);
            if ($owns) { $pdo->commit(); }
        } catch (ProductSimilarException $exception) {
            if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
    }

    /** @return array{0:string,1:string} */
    private function canonical(string $origin, string $target): array
    {
        $origin = $this->normalize($origin);
        $target = $this->normalize($target);
        if ($origin === '' || $target === '' || $origin === $target) {
            throw new ProductSimilarException(['target' => 'No puedes relacionar un producto consigo mismo.']);
        }
        return $origin < $target ? [$origin, $target] : [$target, $origin];
    }

    private function assertActive(string $id, string $field): void
    {
        if ($this->relations->activeProduct($id) === null) {
            throw new ProductSimilarException([$field => 'El producto debe existir y estar activo.']);
        }
    }

    private function normalize(string $id): string
    {
        $id = strtoupper(trim($id));
        return preg_match('/^[A-Z0-9]{1,16}$/', $id) === 1 ? $id : '';
    }
}
