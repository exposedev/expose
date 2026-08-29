<?php

namespace Tests\Unit;

use Expose\Client\Http\ViteDevServer;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

class ViteDevServerTest extends TestCase
{
    /** @var ViteDevServer */
    protected $viteDevServer;

    public function setUp(): void
    {
        parent::setUp();

        $this->viteDevServer = new ViteDevServer();
    }

    /** @test */
    public function it_discovers_the_dev_server_from_html_responses()
    {
        $html = '<script type="module" src="http://127.0.0.1:5173/@vite/client"></script>';

        $rewritten = $this->viteDevServer->rewriteUrls($html, 'myhost.test', 80, 'https://mysite.sharedwith.dev');

        $this->assertSame('<script type="module" src="https://mysite.sharedwith.dev/@vite/client"></script>', $rewritten);

        $this->assertSame([
            'scheme' => 'http',
            'host' => '127.0.0.1',
            'port' => 5173,
        ], $this->viteDevServer->get());
    }

    /** @test */
    public function it_discovers_a_secure_dev_server_on_the_local_hostname()
    {
        $html = '<script type="module" src="https://myhost.test:5173/resources/js/app.js"></script>';

        $rewritten = $this->viteDevServer->rewriteUrls($html, 'myhost.test', 443, 'https://mysite.sharedwith.dev');

        $this->assertSame('<script type="module" src="https://mysite.sharedwith.dev/resources/js/app.js"></script>', $rewritten);

        $this->assertSame([
            'scheme' => 'https',
            'host' => 'myhost.test',
            'port' => 5173,
        ], $this->viteDevServer->get());
    }

    /** @test */
    public function it_does_not_discover_the_shared_app_itself_as_dev_server()
    {
        $html = '<a href="http://127.0.0.1:8000/login">Login</a>';

        $rewritten = $this->viteDevServer->rewriteUrls($html, '127.0.0.1', 8000, 'https://mysite.sharedwith.dev');

        $this->assertSame('<a href="https://mysite.sharedwith.dev/login">Login</a>', $rewritten);

        $this->assertNull($this->viteDevServer->get());
    }

    /** @test */
    public function it_updates_the_dev_server_when_it_restarts_on_a_different_port()
    {
        $this->viteDevServer->set('http', '127.0.0.1', 5173);

        $this->viteDevServer->rewriteUrls(
            '<script src="http://127.0.0.1:5175/@vite/client"></script>',
            'myhost.test',
            80,
            'https://mysite.sharedwith.dev'
        );

        $this->assertSame(5175, $this->viteDevServer->get()['port']);
    }

    /** @test */
    public function it_does_not_discover_the_dev_server_from_dev_server_responses()
    {
        $this->viteDevServer->set('http', '[::1]', 5175);

        $this->viteDevServer->rewriteUrls(
            'const api = "http://localhost:3000/api";',
            'myhost.test',
            80,
            'https://mysite.sharedwith.dev',
            false
        );

        $this->assertSame(5175, $this->viteDevServer->get()['port']);
    }

    /** @test */
    public function it_leaves_urls_without_an_explicit_port_untouched()
    {
        $html = '<a href="http://myhost.test/login">Login</a>';

        $rewritten = $this->viteDevServer->rewriteUrls($html, 'myhost.test', 80, 'https://mysite.sharedwith.dev');

        $this->assertSame($html, $rewritten);
        $this->assertNull($this->viteDevServer->get());
    }

    /** @test */
    public function it_can_seed_the_dev_server_from_an_existing_hot_file()
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'expose-hot-');
        file_put_contents($hotFile, 'http://127.0.0.1:5173');

        $this->viteDevServer->setHotFilePath($hotFile);

        $this->assertSame([
            'scheme' => 'http',
            'host' => '127.0.0.1',
            'port' => 5173,
        ], $this->viteDevServer->get());

        $this->assertSame('http://127.0.0.1:5173', file_get_contents($hotFile), 'The hot file may never be modified.');

        unlink($hotFile);
    }

    /** @test */
    public function it_ignores_missing_or_invalid_hot_files()
    {
        $this->viteDevServer->setHotFilePath('/does/not/exist/hot');
        $this->assertNull($this->viteDevServer->get());

        $hotFile = tempnam(sys_get_temp_dir(), 'expose-hot-');
        file_put_contents($hotFile, 'not-a-url');

        $viteDevServer = new ViteDevServer();
        $viteDevServer->setHotFilePath($hotFile);
        $this->assertNull($viteDevServer->get());

        unlink($hotFile);
    }

    /** @test */
    public function it_detects_vite_requests_by_path()
    {
        $this->viteDevServer->set('http', '127.0.0.1', 5173);

        $vitePaths = [
            '/@vite/client',
            '/@id/some-module',
            '/@fs/Users/marcel/project/resources/js/app.js',
            '/@react-refresh',
            '/node_modules/.vite/deps/vue.js',
            '/resources/js/app.js',
            '/resources/css/app.css?direct',
            '/__laravel_vite_plugin__/fonts/2d9e91e07fbb2d42.woff2',
        ];

        foreach ($vitePaths as $path) {
            $this->assertTrue(
                $this->viteDevServer->isViteRequest(new Request('GET', "http://myhost.test{$path}")),
                "Expected {$path} to be detected as a Vite request."
            );
        }

        $appPaths = [
            '/',
            '/login',
            '/@vite',
            '/resources',
            '/build/assets/app.js',
            '/node_modules',
        ];

        foreach ($appPaths as $path) {
            $this->assertFalse(
                $this->viteDevServer->isViteRequest(new Request('GET', "http://myhost.test{$path}")),
                "Expected {$path} to be routed to the application."
            );
        }
    }

    /** @test */
    public function it_detects_the_vite_hmr_websocket_upgrade()
    {
        $this->viteDevServer->set('http', '127.0.0.1', 5173);

        $request = new Request('GET', 'http://myhost.test/', [
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Protocol' => 'vite-hmr',
        ]);

        $this->assertTrue($this->viteDevServer->isViteRequest($request));

        $otherSocket = new Request('GET', 'http://myhost.test/', [
            'Upgrade' => 'websocket',
        ]);

        $this->assertFalse($this->viteDevServer->isViteRequest($otherSocket));
    }

    /** @test */
    public function it_does_not_detect_vite_requests_without_a_discovered_dev_server()
    {
        $this->assertFalse($this->viteDevServer->isViteRequest(new Request('GET', 'http://myhost.test/@vite/client')));
    }

    /** @test */
    public function it_rewrites_vite_requests_to_the_dev_server()
    {
        $this->viteDevServer->set('https', 'myhost.test', 5173);

        $request = new Request('GET', 'http://myhost.test/@vite/client?foo=bar');

        $rewritten = $this->viteDevServer->rewriteRequest($request);

        $this->assertSame('https://myhost.test:5173/@vite/client?foo=bar', (string) $rewritten->getUri());
        $this->assertSame('localhost', $rewritten->getHeaderLine('Host'));
    }

    /** @test */
    public function it_rewrites_vite_requests_to_an_ipv6_dev_server()
    {
        $this->viteDevServer->set('http', '[::1]', 5175);

        $request = new Request('GET', 'http://myhost.test/@vite/client');

        $rewritten = $this->viteDevServer->rewriteRequest($request);

        $this->assertSame('http://[::1]:5175/@vite/client', (string) $rewritten->getUri());
        $this->assertSame('localhost', $rewritten->getHeaderLine('Host'));
    }
}
