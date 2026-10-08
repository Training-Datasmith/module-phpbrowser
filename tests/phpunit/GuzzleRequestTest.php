<?php

declare(strict_types=1);

use Codeception\Lib\Connector\Guzzle;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\BrowserKit\Cookie;

final class GuzzleRequestTest extends GuzzleConnectorTestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->history = [];
    }

    public function testSetHeaderSendsValueAndEmptyValueDeletesIt(): void
    {
        $browser = $this->browserWithHistory([
            new Response(200),
            new Response(200),
            new Response(200),
        ]);
        $browser->setHeader('X-Trace', 'alpha');
        $browser->request('GET', '/one');
        $this->assertTrue($this->lastRequest()->hasHeader('X-Trace'));
        $this->assertSame('alpha', $this->lastRequest()->getHeaderLine('X-Trace'));

        $browser->setHeader('X-Trace', '');
        $browser->request('GET', '/two');
        $this->assertFalse($this->lastRequest()->hasHeader('X-Trace'));

        $browser->setHeader('X-Trace', 'beta');
        $browser->deleteHeader('X-Trace');
        $browser->request('GET', '/three');
        $this->assertFalse($this->lastRequest()->hasHeader('X-Trace'));
    }

    public function testSetAuthBasicSendsCredentialsAndBlankUserClearsThem(): void
    {
        $browser = $this->browserWithHistory([new Response(200), new Response(200)]);
        $browser->setAuth('ada', 'secret');
        $browser->request('GET', '/auth');
        $this->assertSame('ada:secret', $this->decodeBasicAuth($this->lastRequest()));

        $browser->setAuth('', 'x');
        $browser->request('GET', '/no-auth');
        $this->assertFalse($this->lastRequest()->hasHeader('Authorization'));
    }

    public function testPostParametersAreFormEncoded(): void
    {
        $browser = $this->browserWithHistory([new Response(200)]);
        $browser->request('POST', '/form', ['show' => 'author'], [], [], null);
        $this->assertSame('show=author', (string) $this->lastRequest()->getBody());
    }

    public function testRawJsonBodyIsPreserved(): void
    {
        $browser = $this->browserWithHistory([new Response(200)]);
        $browser->request(
            'POST',
            '/api',
            [],
            [],
            ['HTTP_CONTENT_TYPE' => 'application/json'],
            '{"a":1}'
        );
        $this->assertSame('{"a":1}', (string) $this->lastRequest()->getBody());
        $this->assertArrayNotHasKey('form_params', $this->history[0]['options']);
    }

    public function testMultipartFileAndNestedFields(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'guz');
        file_put_contents($tmp, 'file-bytes');
        $browser = $this->browserWithHistory([new Response(200)]);
        $browser->request(
            'POST',
            '/upload',
            ['title' => 'Hello', 'meta' => ['lang' => 'en']],
            ['file' => ['tmp_name' => $tmp, 'name' => 'note.txt', 'type' => 'text/plain']],
            [],
            null
        );
        $body = (string) $this->lastRequest()->getBody();
        $this->assertStringContainsString('name="title"', $body);
        $this->assertStringContainsString('Hello', $body);
        $this->assertStringContainsString('name="meta[lang]"', $body);
        $this->assertStringContainsString('filename="note.txt"', $body);
        $this->assertStringContainsString('file-bytes', $body);
        @unlink($tmp);
    }

    public function testMultipartAcceptsAStringPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'guz');
        file_put_contents($tmp, 'path-bytes');
        $browser = $this->browserWithHistory([new Response(200)]);
        $browser->request('POST', '/upload', [], ['upload' => $tmp], [], null);
        $body = (string) $this->lastRequest()->getBody();
        $this->assertStringContainsString('name="upload"', $body);
        $this->assertStringContainsString('path-bytes', $body);
        @unlink($tmp);
    }

    public function testCookieWithoutDomainUsesRequestHost(): void
    {
        $browser = $this->browserWithHistory([new Response(200)]);
        $browser->getCookieJar()->set(new Cookie('session', 'abc'));
        $browser->request('GET', 'http://127.0.0.1/');
        $jar = $this->history[0]['options']['cookies'];
        $cookies = $jar->toArray();
        $this->assertCount(1, $cookies);
        $this->assertSame('127.0.0.1', $cookies[0]['Domain']);
        $this->assertSame('session', $cookies[0]['Name']);
        $this->assertSame('abc', $cookies[0]['Value']);
    }

    public function testRequestExceptionWithResponseIsReturnedAsThePage(): void
    {
        $response = new Response(503, [], 'err-body');
        $exception = RequestException::create(new Request('GET', 'http://127.0.0.1/fail'), $response);
        $browser = $this->createBrowser([$exception]);
        $browser->request('GET', '/fail');
        $this->assertSame(503, $browser->getInternalResponse()->getStatusCode());
        $this->assertSame('err-body', $browser->getInternalResponse()->getContent());
    }

    public function testConnectExceptionWithoutResponsePropagates(): void
    {
        $exception = new ConnectException('connection failed', new Request('GET', 'http://127.0.0.1/offline'));
        $browser = $this->createBrowser([$exception]);
        $this->expectException(ConnectException::class);
        $browser->request('GET', '/offline');
    }

    public function testAwsAuthAddsSigV4Authorization(): void
    {
        $browser = $this->browserWithHistory([new Response(200)]);
        $browser->setAwsAuth([
            'key' => 'AKIAEXAMPLE',
            'secret' => 'secret',
            'service' => 's3',
            'region' => 'us-east-1',
        ]);
        $browser->request('GET', '/bucket');
        $auth = $this->lastRequest()->getHeaderLine('Authorization');
        $this->assertMatchesRegularExpression('/^AWS4-HMAC-SHA256 /', $auth);
        $this->assertStringContainsString('AKIAEXAMPLE/', $auth);
        $this->assertStringContainsString('/us-east-1/s3/aws4_request', $auth);
        $this->assertStringContainsString('SignedHeaders=', $auth);
        $this->assertStringContainsString('host', $auth);
    }

    /**
     * @param array<int, Response|RequestException|ConnectException> $responses
     */
    private function browserWithHistory(array $responses): Guzzle
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new GuzzleClient(['handler' => $stack, 'base_uri' => 'http://127.0.0.1']);
        $browser = new Guzzle();
        $browser->setClient($client);

        return $browser;
    }

    private function lastRequest(): Request
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    private function decodeBasicAuth(Request $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        $this->assertStringStartsWith('Basic ', $header);

        return base64_decode(substr($header, 6), true);
    }

}
