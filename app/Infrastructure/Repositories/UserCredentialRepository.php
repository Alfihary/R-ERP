<?php
declare(strict_types=1);
namespace App\Infrastructure\Repositories;
use App\Infrastructure\Database\ConnectionProvider;
use PDO;
final class UserCredentialRepository
{
    public function __construct(private readonly ConnectionProvider $connection){}
    public function activeUser(int $userId):?array
    {
        $q=$this->connection->pdo()->prepare('SELECT id,username,email FROM usuarios WHERE id=:id AND activo=1 AND deleted_at IS NULL LIMIT 1');$q->execute(['id'=>$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
    public function credentialByUser(int $userId):?array
    {
        $q=$this->connection->pdo()->prepare("SELECT id,'VIGENTE' AS estatus,created_at AS emitida_en,NULL AS expira_en,created_at AS creado_en FROM usuarios WHERE id=:id AND activo=1 AND deleted_at IS NULL LIMIT 1");$q->execute(['id'=>$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
    public function createBaseCredential(int $userId):void {}
    public function visualDataByUser(int $userId):?array
    {
        $sql=<<<'SQL'
SELECT u.id AS usuario_id,u.username,u.email,u.nombre AS primer_nombre,u.nombre_2 AS segundo_nombre,
       u.apellido_paterno,u.apellido_materno,u.puesto,u.telefono AS telefono_fijo,u.telefono_movil,
       v.whatsapp,CONCAT_WS(', ',NULLIF(v.direccion,''),NULLIF(v.ciudad,''),NULLIF(v.estado,''),NULLIF(v.codigo_postal,''),NULLIF(v.pais,'')) AS ubicacion_publica,
       IF(u.foto IS NULL OR u.foto='',NULL,u.id) AS foto_id,u.foto AS foto_ruta_relativa,
       SUBSTRING_INDEX(u.foto,'/',-1) AS foto_nombre_archivo,
       NULL AS foto_mime,
       LOWER(SUBSTRING_INDEX(u.foto,'.',-1)) AS foto_extension,NULL AS foto_tamano_bytes,NULL AS foto_creado_en,
       e.nombre_comercial AS empresa_nombre,a.nombre AS almacen_nombre,v.slug_publico,v.activa AS vcard_activa
FROM usuarios u LEFT JOIN usuario_vcards v ON v.usuario_id=u.id AND v.deleted_at IS NULL
LEFT JOIN empresas e ON e.id=u.empresa_id AND e.deleted_at IS NULL
LEFT JOIN almacenes a ON a.id=u.almacen_id AND a.deleted_at IS NULL
WHERE u.id=:usuario_id AND u.activo=1 AND u.deleted_at IS NULL LIMIT 1
SQL;
        $q=$this->connection->pdo()->prepare($sql);$q->execute(['usuario_id'=>$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
    public function activePhotoByUser(int $userId):?array
    {
        $q=$this->connection->pdo()->prepare("SELECT u.foto AS ruta_relativa,SUBSTRING_INDEX(u.foto,'/',-1) AS nombre_archivo,NULL AS mime,LOWER(SUBSTRING_INDEX(u.foto,'.',-1)) AS extension,NULL AS tamano_bytes FROM usuarios u WHERE u.id=:usuario_id AND u.activo=1 AND u.deleted_at IS NULL AND u.foto IS NOT NULL AND u.foto<>'' LIMIT 1");$q->execute(['usuario_id'=>$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
}
