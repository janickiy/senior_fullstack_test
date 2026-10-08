<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConcurrentImportTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->shopId = 'test-parallel-'.bin2hex(random_bytes(8));
    }

    public function testSimultaneousImportsCreateOneOrderPerMarketplaceIdentifier(): void
    {
        $responses = $this->importConcurrently([$this->fixture(), $this->fixture()]);
        $totals = array_fill_keys(['created', 'unchanged', 'updated', 'duplicate', 'rejected'], 0);

        foreach ($responses as $response) {
            self::assertSame(200, $response['status'], json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            self::assertSame(11, $response['body']['summary']['total']);
            foreach ($totals as $outcome => $count) {
                $totals[$outcome] += $response['body']['summary'][$outcome];
            }
        }

        self::assertSame(
            ['created' => 7, 'unchanged' => 7, 'updated' => 0, 'duplicate' => 2, 'rejected' => 6],
            $totals,
            json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $orders = $this->listOrders();
        self::assertCount(7, $orders);
        self::assertCount(7, array_unique(array_column($orders, 'marketplace_id')));
    }

    public function testSimultaneousStatusUpdatesCannotRollBackADeliveredOrder(): void
    {
        $original = $this->fixture()['orders'][0];
        $this->client->jsonRequest('POST', $this->importUrl(), ['orders' => [$original]]);
        self::assertResponseStatusCodeSame(200);
        $before = $this->listOrders()[0];
        $delivered = $original;
        $delivered['status'] = 'DONE';

        $responses = $this->importConcurrently([
            ['orders' => [$delivered]],
            ['orders' => [$original]],
        ]);

        foreach ($responses as $response) {
            self::assertSame(200, $response['status'], json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            self::assertSame(0, $response['body']['summary']['created']);
            self::assertSame(0, $response['body']['summary']['rejected']);
        }
        self::assertSame(1, array_sum(array_map(static fn (array $response): int => $response['body']['summary']['updated'], $responses)));
        self::assertSame(1, array_sum(array_map(static fn (array $response): int => $response['body']['summary']['unchanged'], $responses)));
        $before['status'] = 'delivered';
        self::assertSame([$before], $this->listOrders());
    }

    private function importConcurrently(array $payloads): array
    {
        $workers = [];
        $projectDirectory = dirname(__DIR__, 2);
        $environment = array_merge(getenv(), ['APP_ENV' => 'test', 'APP_DEBUG' => static::$kernel->isDebug() ? '1' : '0']);

        try {
            foreach ($payloads as $payload) {
                $payloadFile = tempnam(sys_get_temp_dir(), 'order-import-');
                self::assertNotFalse($payloadFile);
                file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR));
                $process = proc_open(
                    [PHP_BINARY, $projectDirectory.'/tests/Support/import_worker.php', $this->shopId, $payloadFile],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $projectDirectory,
                    $environment,
                );
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 20);
                $workers[] = ['process' => $process, 'pipes' => $pipes, 'payload_file' => $payloadFile, 'stdout' => '', 'stderr' => ''];
            }

            foreach ($workers as $worker) {
                self::assertSame("READY\n", fgets($worker['pipes'][1]), 'A concurrent HTTP worker did not boot.');
            }
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "GO\n");
                fflush($worker['pipes'][0]);
                fclose($worker['pipes'][0]);
                stream_set_blocking($worker['pipes'][1], false);
                stream_set_blocking($worker['pipes'][2], false);
            }

            $deadline = microtime(true) + 45;
            do {
                $streams = [];
                $pending = false;
                foreach ($workers as $worker) {
                    foreach ([1, 2] as $descriptor) {
                        if (!feof($worker['pipes'][$descriptor])) {
                            $streams[] = $worker['pipes'][$descriptor];
                            $pending = true;
                        }
                    }
                }
                if (!$pending) {
                    break;
                }
                self::assertLessThan($deadline, microtime(true), 'Concurrent import workers timed out.');
                $write = null;
                $except = null;
                stream_select($streams, $write, $except, 1);
                foreach ($workers as &$worker) {
                    $worker['stdout'] .= stream_get_contents($worker['pipes'][1]);
                    $worker['stderr'] .= stream_get_contents($worker['pipes'][2]);
                }
                unset($worker);
            } while ($pending);

            $responses = [];
            foreach ($workers as $worker) {
                self::assertNotEmpty(trim($worker['stdout']), 'Concurrent import worker failed: '.$worker['stderr']);
                $response = json_decode(trim($worker['stdout']), true, flags: JSON_THROW_ON_ERROR);
                $response['diagnostics'] = trim($worker['stderr']);
                $responses[] = $response;
            }

            return $responses;
        } finally {
            foreach ($workers as $worker) {
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($worker['process'])) {
                    if (proc_get_status($worker['process'])['running']) {
                        proc_terminate($worker['process']);
                    }
                    proc_close($worker['process']);
                }
                unlink($worker['payload_file']);
            }
        }
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/marketplace-orders.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function listOrders(): array
    {
        $this->client->request('GET', '/api/shops/'.$this->shopId.'/orders');
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['items'];
    }

    private function importUrl(): string
    {
        return '/api/shops/'.$this->shopId.'/orders/import';
    }
}
