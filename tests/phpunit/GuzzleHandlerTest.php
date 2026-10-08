<?php

declare(strict_types=1);

use Codeception\Lib\Connector\Guzzle;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RecordingHandler
{
    public static int $invocations = 0;

    public static ?string $lastPath = null;

    public function __invoke($request, array $options)
    {
        self::$invocations++;
        self::$lastPath = $request->getUri()->getPath();

        return PromiseCreate::promiseFor(new Response(200, [], 'from-named-class'));
    }
}

final class GuzzleHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingHandler::$invocations = 0;
        RecordingHandler::$lastPath = null;
    }

    public function testCreateHandlerReturnsTheSameStack(): void
    {
        $stack = HandlerStack::create(new MockHandler([]));
        $this->assertSame($stack, Guzzle::createHandler($stack));
    }

    public function testCreateHandlerCurlBottomIsCurlHandler(): void
    {
        $stack = Guzzle::createHandler('curl');
        $handler = $this->unwrapHandler($stack);
        $this->assertInstanceOf(CurlHandler::class, $handler);
    }

    public function testCreateHandlerStreamBottomIsStreamHandler(): void
    {
        $stack = Guzzle::createHandler('stream');
        $handler = $this->unwrapHandler($stack);
        $this->assertInstanceOf(StreamHandler::class, $handler);
    }

    public function testCreateHandlerInvokesCallable(): void
    {
        $called = false;
        $stack = Guzzle::createHandler(function () use (&$called) {
            $called = true;

            return PromiseCreate::promiseFor(new Response(200, [], 'from-callable'));
        });
        $client = new \GuzzleHttp\Client(['handler' => $stack, 'base_uri' => 'http://127.0.0.1']);
        $this->assertSame('from-callable', (string) $client->get('resource')->getBody());
        $this->assertTrue($called);
    }

    public function testCreateHandlerConstructsNamedClass(): void
    {
        $stack = Guzzle::createHandler(RecordingHandler::class);
        $client = new \GuzzleHttp\Client(['handler' => $stack, 'base_uri' => 'http://127.0.0.1']);
        $this->assertSame('from-named-class', (string) $client->get('named')->getBody());
        $this->assertSame(1, RecordingHandler::$invocations);
        $this->assertSame('/named', RecordingHandler::$lastPath);
    }

    private function unwrapHandler(HandlerStack $stack): object
    {
        $ref = new ReflectionObject($stack);
        $prop = $ref->getProperty('handler');
        $prop->setAccessible(true);

        return $prop->getValue($stack);
    }
}
