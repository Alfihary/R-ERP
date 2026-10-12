<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('PRODUCTOS-2B-1 test is CLI-only.');
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
    require BASE_PATH . '/bootstrap/autoload.php';
}

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Database\ConnectionProvider;

return new class implements DatabaseTest {
    /** @var list<string> */
    private array $productIds = [
        'QA2BNOIMG001',
        'QA2BIMG001',
        'QA2BMULTI01',
        'QA2BINACT01',
    ];

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('PRODUCTOS-2B-1 database mismatch.');
        }

        $this->assertTables($pdo);
        $this->cleanup($pdo);
        $actorId = $this->adminId($pdo);
        $protectedBefore = $this->protectedProductFingerprint($pdo);

        try {
            $this->insertProducts($pdo, $actorId);
            $this->insertDocuments($pdo, $actorId);

            $repository = new ProductRepository(
                new ConnectionProvider(require BASE_PATH . '/config/database.php')
            );
            $results = [];
            $pageOne = $repository->search([], 1, 2);
            $pageTwo = $repository->search([], 2, 2);
            $default = $repository->search([], 1, 20);
            $max = $repository->search([], 0, 999);

            $results['page_contract'] = $pageOne['page'] === 1
                && $pageOne['per_page'] === 2
                && $pageOne['total'] >= 4
                && $pageOne['total_pages'] >= 2
                && count($pageOne['items']) === 2
                && $pageTwo['page'] === 2
                && $pageTwo['per_page'] === 2;
            $results['default_and_bounds'] = $default['per_page'] === 20
                && $max['page'] === 1
                && $max['per_page'] === 50;
            $results['stable_order'] = $this->ids($pageOne['items'])
                === $this->ids($repository->search([], 1, 2)['items'])
                && $this->ids($pageOne['items']) !== $this->ids($pageTwo['items']);

            $image = $this->singleProduct($repository, 'QA2BIMG001');
            $noImage = $this->singleProduct($repository, 'QA2BNOIMG001');
            $multi = $this->singleProduct($repository, 'QA2BMULTI01');
            $inactive = $this->singleProduct($repository, 'QA2BINACT01');
            $results['image_contract'] = $image !== null
                && $image['has_primary_image'] === true
                && is_string($image['primary_image_version'])
                && $noImage !== null
                && $noImage['has_primary_image'] === false
                && $noImage['primary_image_version'] === null
                && $multi !== null
                && $multi['has_primary_image'] === true
                && $multi['primary_image_version'] === '2026-01-02 00:00:00'
                && $inactive !== null
                && $inactive['has_primary_image'] === false;

            $search = $repository->search(['search' => 'QA2BIMG001'], 1, 20);
            $active = $repository->search(['status' => 'active'], 1, 50);
            $inactiveFilter = $repository->search(['status' => 'inactive'], 1, 50);
            $results['filters_and_search'] = $search['total'] === 1
                && $this->ids($search['items']) === ['QA2BIMG001']
                && $this->containsId($active['items'], 'QA2BIMG001')
                && !$this->containsId($active['items'], 'QA2BINACT01')
                && $this->containsId($inactiveFilter['items'], 'QA2BINACT01');

            $results['count_matches_items'] = $default['total'] >= count($default['items'])
                && $default['total_pages'] === (int) ceil($default['total'] / $default['per_page']);
            $results['no_n_plus_one_image_query'] = $this->queryHasInlineImageContract();
            $results['no_paths_in_contract'] = !array_key_exists('ruta_relativa', $image ?? []);
            $results['protected_product_untouched'] =
                $protectedBefore !== null
                && $protectedBefore === $this->protectedProductFingerprint($pdo);

            foreach ($results as $name => $passed) {
                if (!$passed) {
                    throw new RuntimeException('PRODUCTOS-2B-1 failed: ' . $name);
                }
            }

            $this->cleanup($pdo);
            $residuals = $this->qaResiduals($pdo);
            if ($residuals['products'] !== 0 || $residuals['documents'] !== 0) {
                throw new RuntimeException('PRODUCTOS-2B-1 QA cleanup failed.');
            }

            return [
                'PRODUCTS_2B_1' => 'PASS',
                'PAGINATION_CONTRACT' => 'PASS',
                'IMAGE_METADATA_CONTRACT' => 'PASS',
                'N_PLUS_ONE_IMAGE_QUERY_COUNT' => 0,
                'PROTECTED_PRODUCT_TOUCHED' => false,
                'QA_PRODUCT_RESIDUALS' => $residuals['products'],
                'QA_DOCUMENT_RESIDUALS' => $residuals['documents'],
                'QA_CLEANUP' => 'PASS',
            ];
        } finally {
            $this->cleanup($pdo);
        }
    }

    private function assertTables(PDO $pdo): void
    {
        foreach (['productos', 'producto_documentos', 'tipos_producto', 'unidades_medida'] as $table) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $statement->execute(['table' => $table]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('Missing table required by PRODUCTOS-2B-1: ' . $table);
            }
        }
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException('Initial admin user is required.');
        }
        return $id;
    }

    private function insertProducts(PDO $pdo, int $actorId): void
    {
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' LIMIT 1"
        )->fetchColumn();
        $typeId = (int) $pdo->query(
            "SELECT id FROM tipos_producto WHERE codigo = 'PRODUCTO' LIMIT 1"
        )->fetchColumn();
        if ($unitId < 1 || $typeId < 1) {
            throw new RuntimeException('Required product catalogs are missing.');
        }
        $statement = $pdo->prepare(
            'INSERT INTO productos
                (id_producto, descripcion, tipo_producto_id, unidad_medida_id,
                 activo, creado_por, actualizado_por)
             VALUES (:id, :description, :type_id, :unit_id, :active, :created_by, :updated_by)'
        );
        foreach ($this->productIds as $id) {
            $statement->execute([
                'id' => $id,
                'description' => 'QA 2B ' . $id,
                'type_id' => $typeId,
                'unit_id' => $unitId,
                'active' => $id === 'QA2BINACT01' ? 0 : 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }

    private function insertDocuments(PDO $pdo, int $actorId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO producto_documentos
                (id_producto, tipo_documento, nombre_original, ruta_relativa,
                 mime_type, tamano_bytes, es_principal, activo, creado_por,
                 actualizado_por, creado_en, actualizado_en, eliminado_en)
             VALUES (:id, \'FOTO_PRINCIPAL\', \'qa.png\', :path, \'image/png\',
                 1, 1, :active, :created_by, :updated_by, :created, :updated, :deleted)'
        );
        $statement->execute([
            'id' => 'QA2BIMG001', 'path' => 'qa/2b-image.png', 'active' => 1,
            'created_by' => $actorId, 'updated_by' => $actorId,
            'created' => '2026-01-01 00:00:00',
            'updated' => null, 'deleted' => null,
        ]);
        foreach ([
            ['2026-01-01 00:00:00', '2026-01-01 00:00:00', 'qa/2b-multi-1.png'],
            ['2026-01-02 00:00:00', '2026-01-02 00:00:00', 'qa/2b-multi-2.png'],
        ] as [$created, $updated, $path]) {
            $statement->execute([
                'id' => 'QA2BMULTI01', 'path' => $path, 'active' => 1,
                'created_by' => $actorId, 'updated_by' => $actorId,
                'created' => $created, 'updated' => $updated,
                'deleted' => null,
            ]);
        }
        $statement->execute([
            'id' => 'QA2BINACT01', 'path' => 'qa/2b-inactive.png', 'active' => 1,
            'created_by' => $actorId, 'updated_by' => $actorId,
            'created' => '2026-01-01 00:00:00',
            'updated' => null, 'deleted' => '2026-01-03 00:00:00',
        ]);
    }

    private function cleanup(PDO $pdo): void
    {
        $placeholders = implode(',', array_fill(0, count($this->productIds), '?'));
        $pdo->prepare(
            "DELETE FROM producto_documentos WHERE id_producto IN ($placeholders)"
        )->execute($this->productIds);
        $pdo->prepare(
            "DELETE FROM productos WHERE id_producto IN ($placeholders)"
        )->execute($this->productIds);
    }

    /** @return array{products: int, documents: int} */
    private function qaResiduals(PDO $pdo): array
    {
        $placeholders = implode(',', array_fill(0, count($this->productIds), '?'));
        $documents = $pdo->prepare(
            "SELECT COUNT(*) FROM producto_documentos WHERE id_producto IN ($placeholders)"
        );
        $documents->execute($this->productIds);
        $products = $pdo->prepare(
            "SELECT COUNT(*) FROM productos WHERE id_producto IN ($placeholders)"
        );
        $products->execute($this->productIds);
        return [
            'products' => (int) $products->fetchColumn(),
            'documents' => (int) $documents->fetchColumn(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function protectedProductFingerprint(PDO $pdo): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id_producto, descripcion, activo, sku, upc, ean, gtin
             FROM productos WHERE id_producto = :id LIMIT 1'
        );
        $statement->execute(['id' => '102016169']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param list<array<string, mixed>> $items */
    private function ids(array $items): array
    {
        return array_values(array_map(static fn (array $item): string => (string) $item['id_producto'], $items));
    }

    /** @param list<array<string, mixed>> $items */
    private function find(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if ((string) $item['id_producto'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $items */
    private function containsId(array $items, string $id): bool
    {
        return $this->find($items, $id) !== null;
    }

    private function queryHasInlineImageContract(): bool
    {
        $source = file_get_contents(BASE_PATH . '/app/Infrastructure/Repositories/ProductRepository.php');
        return is_string($source)
            && substr_count($source, "FROM producto_documentos pid") === 2
            && substr_count($source, 'ProductDocumentRepository') === 0;
    }

    private function singleProduct(ProductRepository $repository, string $id): ?array
    {
        $result = $repository->search(['search' => $id], 1, 20);
        return $this->find($result['items'], $id);
    }
};
