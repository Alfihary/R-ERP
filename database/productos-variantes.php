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
    $command=$argv[1]??'';
    if(!in_array($command,['migrate','rollback','seed','db:test','status'],true))throw new RuntimeException('Unknown PRODUCTOS-6 command.');
    $options=[];foreach(array_slice($argv,2) as $arg){if(!str_starts_with($arg,'--'))throw new RuntimeException('Unexpected CLI argument.');[$name,$value]=array_pad(explode('=',substr($arg,2),2),2,'');$options[$name]=$value;}
    $database=trim((string)($options['database']??''));$confirmed=trim((string)($options['confirm-database']??''));if($database===''||$database!==$confirmed)throw new RuntimeException('Database must be confirmed twice.');
    $config=require BASE_PATH.'/bootstrap/database.php';if(strtolower((string)$config->get('app.env','production'))==='production')throw new RuntimeException('Disabled in production.');
    $db=$config->get('database',[]);if(!is_array($db)||($db['name']??'')!==$database)throw new RuntimeException('Database target mismatch.');
    $connection=new ConnectionProvider($db);$pdo=$connection->pdo();$GLOBALS['productos_variantes_connection']=$connection;
    $migration=require BASE_PATH.'/database/migrations/productos_variantes_1_001_create_product_variant_tables.php';
    $seed=require BASE_PATH.'/database/seeds/productos_variantes_1_seed_permissions.php';
    $test=require BASE_PATH.'/database/tests/productos_variantes_1_test.php';
    if(!$migration instanceof Migration||!$seed instanceof Seed||!$test instanceof DatabaseTest)throw new RuntimeException('Invalid PRODUCTOS-6 artifact.');
    $runner=new MigrationRunner($pdo);
    $result=match($command){
        'migrate'=>['migration'=>$migration->id(),'result'=>$runner->migrate($migration)],
        'rollback'=>['migration'=>$migration->id(),'result'=>$runner->rollback($migration)],
        'seed'=>(static function()use($seed,$pdo):array{$seed->run($pdo);return['seed'=>$seed->id(),'result'=>'applied'];})(),
        'status'=>['migrations'=>$runner->status()],
        'db:test'=>(static function()use($test,$pdo,$database):array{
            $result=$test->run($pdo,$database);
            $required=['TABLES_EXIST','ENGINE_INNODB','CHARSET_COLLATION_VALID','FOREIGN_KEYS_VALID','UNIQUE_CONSTRAINTS_VALID','GENERATED_COLUMNS_VALID','ONE_ACTIVE_FAMILY_DB_GUARD','ONE_DEFAULT_VARIANT_DB_GUARD','COMBINATION_UNIQUE_DB_GUARD','SECOND_ACTIVE_FAMILY_DIRECT_DB_REJECTED','SECOND_DEFAULT_DIRECT_DB_REJECTED','DUPLICATE_COMBINATION_DIRECT_DB_REJECTED','FAMILY_CREATE','FAMILY_CODE_UNIQUE','FAMILY_UPDATE','FAMILY_WITH_ACTIVE_MEMBERS_DELETE_REJECTED','FAMILY_SOFT_DELETE','ATTRIBUTE_CREATE','ATTRIBUTE_VALUE_CREATE','DUPLICATE_ATTRIBUTE_CODE_REJECTED','DUPLICATE_VALUE_CODE_REJECTED','FAMILY_ATTRIBUTE_ASSIGN','REQUIRED_FLAG_ASSERTED','VALID_PRODUCT_LINK','PRODUCT_WITHOUT_FAMILY_REMAINS_NORMAL','SECOND_ACTIVE_FAMILY_REJECTED','DIRECT_REPARENTING_REJECTED','ATTRIBUTE_NOT_ALLOWED_REJECTED','VALUE_FROM_WRONG_ATTRIBUTE_REJECTED','DUPLICATE_ATTRIBUTE_VALUE_REJECTED','MISSING_REQUIRED_ATTRIBUTE_REJECTED','VALID_COMBINATION','DUPLICATE_COMBINATION_REJECTED','EMPTY_ATTRIBUTE_FAMILY_FIRST_VARIANT_ALLOWED','EMPTY_ATTRIBUTE_FAMILY_SECOND_VARIANT_REJECTED','SAME_COMBINATION_HASH','COMBINATION_HASH_LENGTH','NO_DEFAULT_ALLOWED','SET_DEFAULT','SECOND_DEFAULT_REPLACES_FIRST','MAX_ONE_DEFAULT_ENFORCED','UNLINK_VARIANT','MEMBERSHIP_SOFT_DELETED','PRODUCT_STILL_EXISTS','PRODUCT_PRICE_UNCHANGED','PRODUCT_INVENTORY_UNCHANGED','RELATION_FAILURE_ROLLBACK','ATTRIBUTE_FAILURE_ROLLBACK','DEFAULT_FAILURE_ROLLBACK','NO_PARTIAL_VARIANT_STATE','LEGACY_PRODUCT_WITHOUT_FAMILY_VALID','LEGACY_PRICE_BEHAVIOR_UNCHANGED','LEGACY_INVENTORY_BEHAVIOR_UNCHANGED','QA_FAMILY_RESIDUALS_ZERO','QA_ATTRIBUTE_RESIDUALS_ZERO','QA_ATTRIBUTE_VALUE_RESIDUALS_ZERO','QA_MEMBERSHIP_RESIDUALS_ZERO','QA_VARIANT_VALUE_RESIDUALS_ZERO','QA_PRODUCT_RESIDUALS_ZERO','QA_AUDIT_RESIDUALS_ZERO','QA_USER_RESIDUALS_ZERO'];
            $failed=[];foreach($required as $name){if(($result[$name]??null)!==true&&($name!=='COMBINATION_HASH_LENGTH'||($result[$name]??null)!==32))$failed[]=$name;}$result['_failed_checks']=$failed;if($failed!==[]){echo json_encode(['command'=>'db:test','result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;throw new RuntimeException('PRODUCTOS-6 DB test failed: '.implode(', ',$failed));}return$result;
        })(),
    };
    echo json_encode(['command'=>$command,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $exception){fwrite(STDERR,$exception->getMessage().PHP_EOL);exit(1);}
