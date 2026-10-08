<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    private const ID = 'productos_imagen_1_001_allow_primary_photo_type';
    private const CONSTRAINT = 'chk_producto_documentos_tipo';

    public function id(): string
    {
        return self::ID;
    }

    public function up(PDO $pdo): void
    {
        $this->assertProductDocumentsExists($pdo);
        $this->replaceConstraint(
            $pdo,
            "REGEXP_LIKE(tipo_documento, '^[A-Z0-9_]{1,32}$', 'c')"
        );
    }

    public function down(PDO $pdo): void
    {
        $this->assertProductDocumentsExists($pdo);
        $underscoreRows = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM producto_documentos
             WHERE LOCATE('_', tipo_documento) > 0"
        )->fetchColumn();

        if ($underscoreRows > 0) {
            throw new RuntimeException(
                'Cannot restore chk_producto_documentos_tipo while rows with underscore exist.'
            );
        }

        $this->replaceConstraint(
            $pdo,
            "REGEXP_LIKE(tipo_documento, '^[A-Z0-9]{1,32}$', 'c')"
        );
    }

    private function replaceConstraint(PDO $pdo, string $checkExpression): void
    {
        if ($this->constraintExists($pdo)) {
            $pdo->exec(
                'ALTER TABLE producto_documentos DROP CHECK '
                . self::CONSTRAINT
            );
        }

        $pdo->exec(
            'ALTER TABLE producto_documentos ADD CONSTRAINT '
            . self::CONSTRAINT
            . ' CHECK (' . $checkExpression . ')'
        );
    }

    private function assertProductDocumentsExists(PDO $pdo): void
    {
        $exists = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'producto_documentos'"
        )->fetchColumn();

        if ($exists !== 1) {
            throw new RuntimeException(
                'PRODUCTOS-IMAGEN-1 requires producto_documentos.'
            );
        }
    }

    private function constraintExists(PDO $pdo): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name'
        );
        $statement->execute(['constraint_name' => self::CONSTRAINT]);

        return (int) $statement->fetchColumn() === 1;
    }
};
