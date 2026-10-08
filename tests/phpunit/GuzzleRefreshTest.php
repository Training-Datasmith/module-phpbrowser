<?php

declare(strict_types=1);

final class GuzzleRefreshTest extends GuzzleConnectorTestCase
{
    public function testMetaRefreshFollowsWhenDelayIsBelowMax(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/landed">', 200),
            $this->htmlPage('<h1>landed</h1>', 200),
        ]);
        $browser->request('GET', '/start');
        $this->assertSame('/landed', $this->currentPath($browser));
    }

    public function testMetaRefreshStaysWhenDelayEqualsMax(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="9; url=/landed">', 200),
        ], 'http://127.0.0.1', 9);
        $browser->request('GET', '/start');
        $this->assertSame('/start', $this->currentPath($browser));
        $this->assertStringContainsString('refresh', strtolower($this->responseBody($browser)));
    }

    public function testUnspecifiedMetaIntervalStillRefreshes(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="; url=/landed">', 200),
            $this->htmlPage('<h1>landed</h1>', 200),
        ], 'http://127.0.0.1', 0);
        $browser->request('GET', '/start');
        $this->assertSame('/landed', $this->currentPath($browser));
    }

    public function testMetaRefreshDelayZeroStaysWhenMaxIsZero(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/landed">', 200),
        ], 'http://127.0.0.1', 0);
        $browser->request('GET', '/start');
        $this->assertSame('/start', $this->currentPath($browser));
    }

    public function testHeaderRefreshDelayZeroStaysWhenMaxIsZero(): void
    {
        $browser = $this->createBrowser([
            new \GuzzleHttp\Psr7\Response(200, ['Refresh' => '0; url=/landed', 'Content-Type' => 'text/html'], 'wait'),
        ], 'http://127.0.0.1', 0);
        $browser->request('GET', '/start');
        $this->assertSame('/start', $this->currentPath($browser));
    }

    public function testDifferentUrlStillRefreshes(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/other">', 200),
            $this->htmlPage('<h1>other</h1>', 200),
        ]);
        $browser->request('GET', '/start');
        $this->assertSame('/other', $this->currentPath($browser));
    }

    public function testSelfMetaRefreshDoesNotLoop(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/same">self-page', 200),
        ]);
        $browser->request('GET', '/same');
        $this->assertSame('/same', $this->currentPath($browser));
        $this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
        $this->assertStringContainsString('self-page', $this->responseBody($browser));
    }

    public function testFragmentOnlyRefreshDoesNotRedirect(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/same#section">', 200),
        ]);
        $browser->request('GET', '/same');
        $this->assertSame('/same', $this->currentPath($browser));
    }

    public function testHttpRedirectIsNotRewrittenByMeta(): void
    {
        $browser = $this->createBrowser([
            new \GuzzleHttp\Psr7\Response(
                302,
                ['Location' => '/from-header', 'Content-Type' => 'text/html'],
                '<meta http-equiv="refresh" content="0; url=/from-meta">'
            ),
            $this->htmlPage('<h1>header</h1>', 200),
        ]);
        $browser->request('GET', '/start');
        $this->assertSame('/from-header', $this->currentPath($browser));
    }

    public function testRelativeRefreshUsesHistoryUri(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=next">', 200),
            $this->htmlPage('<h1>next</h1>', 200),
        ]);
        $browser->request('GET', '/dir/page');
        $this->assertSame('/dir/next', $this->currentPath($browser));
    }

    public function testBasePathIsNotDoubled(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/app/info">', 200),
            $this->htmlPage('<h1>info</h1>', 200),
        ], 'http://127.0.0.1/app');
        $browser->request('GET', '/app/start');
        $this->assertSame('/app/info', parse_url($browser->getHistory()->current()->getUri(), PHP_URL_PATH));
    }

    public function testPrefixThatIsNotASegmentIsNotStripped(): void
    {
        $browser = $this->createBrowser([
            $this->htmlPage('<meta http-equiv="refresh" content="0; url=/application">', 200),
            $this->htmlPage('<h1>app</h1>', 200),
        ], 'http://127.0.0.1/app');
        $browser->request('GET', '/app/start');
        $this->assertSame('/app/application', parse_url($browser->getHistory()->current()->getUri(), PHP_URL_PATH));
    }
}
