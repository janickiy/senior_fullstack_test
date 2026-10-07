<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

require dirname(__DIR__).'/bootstrap.php';

$kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if ("GO\n" !== fgets(STDIN)) {
    throw new RuntimeException('The concurrent import start signal was not received.');
}

$request = Request::create(
    '/api/shops/'.$argv[1].'/orders/import',
    'POST',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    content: file_get_contents($argv[2]),
);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

$logger = $kernel->getContainer()->get('test.service_container')->get('logger');
if ($logger instanceof DebugLoggerInterface) {
    foreach ($logger->getLogs($request) as $log) {
        $exception = $log['context']['exception'] ?? null;
        while ($exception instanceof Throwable) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
            $exception = $exception->getPrevious();
        }
    }
}

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR)."\n");
$kernel->shutdown();
