<?php

namespace Tests\Unit;

use Expose\Client\Http\HerdStudio;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

class HerdStudioTest extends TestCase
{
    /** @var string[] */
    protected $configFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->configFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function herdStudio(?array $aiAssistant): HerdStudio
    {
        $path = tempnam(sys_get_temp_dir(), 'herd-json-');
        $this->configFiles[] = $path;

        file_put_contents($path, json_encode(
            is_null($aiAssistant) ? ['other' => true] : ['aiAssistant' => $aiAssistant]
        ));

        return new HerdStudio($path);
    }

    protected function studioSocketRequest(): Request
    {
        return new Request('GET', 'http://myhost.test/ai/ws?site=myhost.test', [
            'Connection' => 'Upgrade',
            'Upgrade' => 'websocket',
        ]);
    }

    /** @test */
    public function it_is_disabled_without_a_herd_configuration()
    {
        $herdStudio = new HerdStudio('/does/not/exist/herd.json');

        $this->assertNull($herdStudio->apiPort());
        $this->assertNull($herdStudio->widgetDevServerPort());
        $this->assertFalse($herdStudio->shouldHandle($this->studioSocketRequest()));
    }

    /** @test */
    public function it_is_disabled_when_the_ai_assistant_is_not_configured_or_disabled()
    {
        $this->assertNull($this->herdStudio(null)->apiPort());
        $this->assertNull($this->herdStudio(['enabled' => false])->apiPort());
    }

    /** @test */
    public function it_ignores_invalid_configuration_files()
    {
        $path = tempnam(sys_get_temp_dir(), 'herd-json-');
        $this->configFiles[] = $path;
        file_put_contents($path, 'not-json');

        $this->assertNull((new HerdStudio($path))->apiPort());
    }

    /** @test */
    public function it_uses_the_default_api_port_when_enabled()
    {
        $this->assertSame(2304, $this->herdStudio(['enabled' => true])->apiPort());
    }

    /** @test */
    public function it_uses_a_configured_api_port()
    {
        $this->assertSame(4000, $this->herdStudio(['enabled' => true, 'apiPort' => 4000])->apiPort());
    }

    /** @test */
    public function it_detects_the_studio_websocket_upgrade()
    {
        $herdStudio = $this->herdStudio(['enabled' => true]);

        $this->assertTrue($herdStudio->shouldHandle($this->studioSocketRequest()));
    }

    /** @test */
    public function it_ignores_plain_requests_to_the_socket_path()
    {
        $herdStudio = $this->herdStudio(['enabled' => true]);

        $this->assertFalse($herdStudio->shouldHandle(
            new Request('GET', 'http://myhost.test/ai/ws?site=myhost.test')
        ));
    }

    /** @test */
    public function it_ignores_websocket_upgrades_on_other_paths()
    {
        $herdStudio = $this->herdStudio(['enabled' => true]);

        $this->assertFalse($herdStudio->shouldHandle(
            new Request('GET', 'http://myhost.test/ws', [
                'Connection' => 'Upgrade',
                'Upgrade' => 'websocket',
            ])
        ));
    }

    /** @test */
    public function it_rewrites_studio_requests_to_the_herd_api()
    {
        $herdStudio = $this->herdStudio(['enabled' => true, 'apiPort' => 2304]);

        $rewritten = $herdStudio->rewriteRequest($this->studioSocketRequest());

        $this->assertSame('http://127.0.0.1:2304/ai/ws?site=myhost.test', (string) $rewritten->getUri());
        $this->assertSame('127.0.0.1:2304', $rewritten->getHeaderLine('Host'));
        $this->assertSame('websocket', $rewritten->getHeaderLine('Upgrade'));
    }

    /** @test */
    public function it_only_exposes_the_widget_dev_server_port_in_dev_mode()
    {
        $this->assertSame(5273, $this->herdStudio(['enabled' => true, 'dev' => true])->widgetDevServerPort());
        $this->assertNull($this->herdStudio(['enabled' => true])->widgetDevServerPort());
        $this->assertNull($this->herdStudio(['enabled' => false, 'dev' => true])->widgetDevServerPort());
    }
}
