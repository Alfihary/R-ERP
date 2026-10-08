<?php
declare(strict_types=1);
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\MigrationRunner;
if(PHP_SAPI!=='cli'){exit(1);} define('BASE_PATH',dirname(__DIR__)); $db=$argv[1]??''; if($db===''||($argv[2]??'')!==$db){fwrite(STDERR,"database confirmation required\n");exit(1);} require BASE_PATH.'/bootstrap/autoload.php'; $config=require BASE_PATH.'/bootstrap/database.php'; if(($config->get('database.name',''))!==$db){throw new RuntimeException('database mismatch');} $pdo=(new ConnectionProvider($config->get('database',[])))->pdo(); $migration=require BASE_PATH.'/database/migrations/cotizaciones_legacy_1_001_create_mvp_tables.php'; $runner=new MigrationRunner($pdo); echo json_encode(['migration'=>$migration->id(),'result'=>$runner->migrate($migration)]).PHP_EOL;
