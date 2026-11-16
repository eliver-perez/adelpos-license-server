<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$job = json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$config = new AdelPos\License\Config($job['config'],false);
$app = new AdelPos\License\App($config,AdelPos\License\Infrastructure\Database::connect($config),new AdelPos\License\Tests\FrozenClock(new DateTimeImmutable('2026-09-25T12:00:00Z')));
try {
    if ($job['kind'] === 'online') {
        $response = $app->activations->execute('activate',$job['input']);
        $result = ['status'=>$response->status,'body'=>$response->body];
    } else {
        $result = ['status'=>200,'body'=>$app->offline->decide(new AdelPos\License\Application\Actor($job['actor'],'superadmin'),$job['operation'],$job['id'],'approved',$job['input'])];
    }
} catch (AdelPos\License\Domain\Problem|AdelPos\License\Domain\ApiProblem $error) { $result = ['status'=>$error->status]; }
echo AdelPos\License\Domain\Protocol::json($result);
