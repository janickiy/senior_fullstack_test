<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Kernel;

final class StatusControllerTest extends WebTestCase
{
    public function testApiStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame([
            'status' => 'ok',
            'framework' => 'Symfony',
            'version' => Kernel::VERSION,
        ], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
