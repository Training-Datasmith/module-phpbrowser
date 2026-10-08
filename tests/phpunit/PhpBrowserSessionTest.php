<?php

declare(strict_types=1);

use Codeception\Lib\ModuleContainer;
use Codeception\Module\PhpBrowser;
use Codeception\Stub;
use Codeception\Test\Cept;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class PhpBrowserSessionTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->history = [];
    }

    public function testPrepareSessionMapsCurlConstantsAndBaseUri(): void
    {
        $module = $this->makeModule(
            [
                'url' => 'http://127.0.0.1:9',
                'curl' => ['CURLOPT_NOBODY' => true],
            ],
            [new Response(204)]
        );
        $config = $module->guzzle->getConfig();
        $this->assertSame('http://127.0.0.1:9', (string) $config['base_uri']);
        $this->assertArrayHasKey(CURLOPT_NOBODY, $config['curl']);
        $this->assertTrue($config['curl'][CURLOPT_NOBODY]);
    }

    public function testPrepareSessionPushesMiddleware(): void
    {
        $runs = 0;
        $middleware = function (callable $handler) use (&$runs) {
            return function ($request, $options) use ($handler, &$runs) {
                $runs++;

                return $handler($request, $options);
            };
        };
        $container = Stub::make(ModuleContainer::class);
        $module = new PhpBrowser($container);
        $module->_setConfig([
            'url' => 'http://127.0.0.1',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], 'ok')])),
            'middleware' => [$middleware],
        ]);
        $module->_initialize();
        $module->client->request('GET', '/ping');
        $this->assertSame(1, $runs);
    }

    public function testAmOnSubdomainReplacesTheLeadingLabel(): void
    {
        $module = $this->makeModule(['url' => 'https://google.com'], []);
        $module->amOnSubdomain('user');
        $this->assertSame('https://user.google.com', $module->_getUrl());

        $module->_reconfigure(['url' => 'https://www.google.com']);
        $module->amOnSubdomain('user');
        $this->assertSame('https://user.google.com', $module->_getUrl());
    }

    public function testAmOnUrlSetsHostAndRequestsPath(): void
    {
        $module = $this->makeModule(['url' => 'http://127.0.0.1'], [
            new Response(200, [], '<html><body>info</body></html>'),
            new Response(200, [], '<html><body>home</body></html>'),
        ]);
        $module->amOnUrl('http://127.0.0.1/info');
        $this->assertSame('http://127.0.0.1', $module->_getUrl());
        $this->assertSame('/info', $this->lastPath($module));

        $module->amOnUrl('http://127.0.0.1');
        $this->assertSame('/', $this->lastPath($module));
    }

    public function testExecuteInGuzzleReturnsClosureResultForThisClient(): void
    {
        $module = $this->makeModule(['url' => 'http://127.0.0.1'], [new Response(204)]);
        $status = $module->executeInGuzzle(function ($client) use ($module) {
            $this->assertSame($module->guzzle, $client);
            $response = $client->get('/ping');

            return $response->getStatusCode();
        });
        $this->assertSame(204, $status);
    }

    public function testSessionsKeepIndependentCookies(): void
    {
        $module = $this->makeModule(['url' => 'http://127.0.0.1'], []);
        $module->setCookie('test_cookie', 'one');
        $session = $module->_backupSession();
        $module->_initializeSession();
        $module->dontSeeCookie('test_cookie');
        $module->setCookie('other', 'two');
        $module->_loadSession($session);
        $module->seeCookie('test_cookie');
        $module->dontSeeCookie('other');
    }

    public function testBackupRestoresHeaders(): void
    {
        $module = $this->makeModule(['url' => 'http://127.0.0.1'], [
            new Response(200, [], '<html></html>'),
        ]);
        $module->setHeader('X-Trace', 'one');
        $session = $module->_backupSession();
        $module->setHeader('X-Trace', 'two');
        $module->_loadSession($session);
        $module->amOnPage('/headers');
        $this->assertSame('one', $this->lastRequestHeader('X-Trace'));
    }

    public function testResponseCodeIsTheHttpStatus(): void
    {
        $module = $this->makeModule(['url' => 'http://127.0.0.1'], [
            new Response(201, [], 'created'),
        ]);
        $module->amOnPage('/created');
        $this->assertSame(201, $module->_getResponseCode());
    }

    public function testAmHttpAuthenticatedSetsAndClearsGuzzleAuth(): void
    {
        $module = $this->makeModule(['url' => 'http://127.0.0.1'], [
            new Response(200, [], '<html></html>'),
            new Response(200, [], '<html></html>'),
        ]);
        $module->amHttpAuthenticated('ada', 'secret');
        $module->amOnPage('/auth');
        $this->assertSame('ada:secret', $this->decodeBasicAuth($this->lastRequest()));

        $module->amHttpAuthenticated('', '');
        $module->amOnPage('/cleared');
        $this->assertFalse($this->lastRequest()->hasHeader('Authorization'));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, Response> $responses
     */
    private function makeModule(array $config, array $responses): PhpBrowser
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $defaults = [
            'url' => 'http://127.0.0.1',
            'handler' => $stack,
        ];
        $container = Stub::make(ModuleContainer::class);
        $module = new PhpBrowser($container);
        $module->_setConfig(array_merge($defaults, $config));
        $module->_initialize();
        $module->_before(Stub::makeEmpty(Cept::class));

        return $module;
    }

    private function lastPath(PhpBrowser $module): string
    {
        $uri = $module->client->getHistory()->current()->getUri();

        return parse_url($uri, PHP_URL_PATH) ?? '';
    }

    private function lastRequest(): \GuzzleHttp\Psr7\Request
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    private function lastRequestHeader(string $name): string
    {
        return $this->lastRequest()->getHeaderLine($name);
    }

    private function decodeBasicAuth(\GuzzleHttp\Psr7\Request $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        $this->assertStringStartsWith('Basic ', $header);

        return base64_decode(substr($header, 6), true);
    }
}
