<?php

declare(strict_types=1);

final class GuzzleResponseHeadersTest extends GuzzleConnectorTestCase
{
    public function testMissingContentTypeDefaultsToTextHtml(): void
    {
        $browser = $this->createBrowser([
            new \GuzzleHttp\Psr7\Response(200, [], '<html></html>'),
        ]);
        $browser->request('GET', '/');
        $this->assertSame('text/html', $browser->getInternalResponse()->getHeader('Content-Type'));
    }

    public function testCharsetIsAppendedFromMeta(): void
    {
        $browser = $this->createBrowser([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html><meta charset="ISO-8859-1"></html>'),
        ]);
        $browser->request('GET', '/');
        $this->assertSame('text/html;charset=ISO-8859-1', $browser->getInternalResponse()->getHeader('Content-Type'));
    }
}
