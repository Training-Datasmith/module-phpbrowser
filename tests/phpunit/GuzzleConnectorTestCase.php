<?php

declare(strict_types=1);

use Codeception\Lib\Connector\Guzzle;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

abstract class GuzzleConnectorTestCase extends TestCase
{
    protected function createBrowser(
        array $responses,
        string $baseUri = 'http://127.0.0.1',
        int $refreshMaxInterval = 10
    ): Guzzle {
        $handler = new MockHandler($responses);
        $stack = HandlerStack::create($handler);
        $client = new GuzzleClient(['handler' => $stack, 'base_uri' => $baseUri]);
        $browser = new Guzzle();
        $browser->setClient($client);
        $browser->setRefreshMaxInterval($refreshMaxInterval);
        $browser->setMaxRedirects(2);

        return $browser;
    }

    protected function requestPath(Guzzle $browser, string $method, string $path): void
    {
        $browser->request($method, $path);
    }

    protected function currentPath(Guzzle $browser): string
    {
        $uri = $browser->getHistory()->current()->getUri();

        return parse_url($uri, PHP_URL_PATH) ?? '';
    }

    protected function responseBody(Guzzle $browser): string
    {
        return $browser->getInternalResponse()->getContent();
    }

    protected function htmlPage(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
