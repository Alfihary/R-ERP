<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
try {
    $command=$argv[1]??'';if(!in_array($command,['migrate','rollback','seed','db:test','status'],true))throw new RuntimeException('Unknown PRODUCTOS-KITS-1 command.');
    $options=[];foreach(array_slice($argv,2) as $argument){if(!str_starts_with($argument,'--'))throw new RuntimeException('Unexpected CLI argument.');[$name,$value]=array_pad(explode('=',substr($argument,2),2),2,'');$options[$name]=$value;}
    $database=trim((string)($options['database']??''));$confirmed=trim((string)($options['confirm-database']??''));if($database===''||$database!==$confirmed)throw new RuntimeException('Database must be confirmed twice.');
    $config=require BASE_PATH.'/bootstrap/database.php';if(strtolower((string)$config->get('app.env','production'))==='production')throw new RuntimeException('Disabled in production.');$databaseConfig=$config->get('database',[]);if(!is_array($databaseConfig)||($databaseConfig['name']??'')!==$database)throw new RuntimeException('Database target mismatch.');
    $provider=new ConnectionProvider($databaseConfig);$pdo=$provider->pdo();$GLOBALS['productos_kits_connection']=$provider;
    $migration=require BASE_PATH.'/database/migrations/productos_kits_1_001_create_product_kit_components.php';$seed=require BASE_PATH.'/database/seeds/productos_kits_1_seed_permissions.php';$test=require BASE_PATH.'/database/tests/productos_kits_1_test.php';if(!$migration instanceof Migration||!$seed instanceof Seed||!$test instanceof DatabaseTest)throw new RuntimeException('Invalid PRODUCTOS-KITS-1 artifact.');$runner=new MigrationRunner($pdo);
    $result=match($command){'migrate'=>['migration'=>$migration->id(),'result'=>$runner->migrate($migration)],'rollback'=>['migration'=>$migration->id(),'result'=>$runner->rollback($migration)],'seed'=>(static function()use($seed,$pdo):array{$seed->run($pdo);return['seed'=>$seed->id(),'result'=>'applied'];})(),'status'=>['migrations'=>$runner->status()],'db:test'=>(static function()use($test,$pdo,$database):array{$r=$test->run($pdo,$database);$required=['TABLE_EXISTS','ENGINE_INNODB','CHARSET_COLLATION_VALID','FOREIGN_KEYS_VALID','UNIQUE_CONSTRAINT_VALID','GENERATED_COLUMN_VALID','CHECK_CONSTRAINTS_VALID','VALID_COMPONENT_ADD','VALID_DECIMAL_QUANTITY','QUANTITY_UPDATE','COMPONENT_REMOVE','RELATION_SOFT_DELETED','RELATION_REACTIVATION','ACTIVE_DUPLICATE_POLICY_VALID','SELF_REFERENCE_REJECTED','NON_KIT_PARENT_REJECTED','NESTED_KIT_COMPONENT_REJECTED','MISSING_COMPONENT_REJECTED','INACTIVE_COMPONENT_REJECTED','DELETED_COMPONENT_REJECTED','ZERO_QUANTITY_REJECTED','NEGATIVE_QUANTITY_REJECTED','EXCESS_PRECISION_REJECTED','KIT_TO_NON_KIT_WITH_ACTIVE_COMPONENTS_REJECTED','TYPE_CHANGE_STATE_PRESERVED','PRODUCT_PRESERVED_AFTER_COMPONENT_REMOVE','PERSISTENCE_FAILURE_NO_PARTIAL_STATE','AUDIT_FAILURE_ROLLBACK','DIRECT_DB_SELF_REFERENCE_REJECTED','DIRECT_DB_ZERO_QUANTITY_REJECTED','DIRECT_DB_ACTIVE_DUPLICATE_REJECTED','NO_PARTIAL_KIT_STATE','QA_KIT_RELATION_RESIDUALS_ZERO','QA_PRODUCT_RESIDUALS_ZERO','QA_AUDIT_RESIDUALS_ZERO','QA_USER_RESIDUALS_ZERO'];$r['_failed_checks']=array_values(array_filter($required,static fn(string $k):bool=>($r[$k]??null)!==true));if($r['_failed_checks']!==[])throw new RuntimeException('PRODUCTOS-KITS-1 DB test failed: '.implode(', ',$r['_failed_checks']));return $r;})()};
    echo json_encode(['command'=>$command,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $exception){fwrite(STDERR,$exception->getMessage().PHP_EOL);exit(1);}
