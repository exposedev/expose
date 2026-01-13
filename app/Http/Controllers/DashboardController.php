<?php

namespace Expose\Client\Http\Controllers;

use Expose\Client\Client;
use Expose\Client\Configuration;
use Expose\Common\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Ratchet\ConnectionInterface;

class DashboardController extends Controller
{
    public function __construct(protected Configuration $configuration)
    {
    }

    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $httpConnection->send(respond_html(
            $this->getBlade($httpConnection, 'client.internal_dashboard', [
                'page' => [
                    'user' => Client::$user,
                    'subdomains' => Client::$subdomains,
                    'max_logs' => config()->get('expose.max_logged_requests', 10),
                    'local_url' => Client::$localUrl,
                    'auth_token' => $this->configuration->auth(),
                    'platform_url' => config('expose.platform_url', 'https://expose.dev'),
                ],

                'jsFile' => $this->getJsFilePath(),
                'cssFile' => $this->getCssFilePath(),
            ])
        ));
    }

    private function getJsFilePath()
    {
        return '/files/build/internal-dashboard/assets/'.collect(scandir(app()->basePath().'/public/build/internal-dashboard/assets/'))->filter(function ($file) {
            return str($file)->startsWith('index-') && str($file)->endsWith('.js');
        })->first();
    }

    private function getCssFilePath()
    {
        return '/files/build/internal-dashboard/assets/'.collect(scandir(app()->basePath().'/public/build/internal-dashboard/assets/'))->filter(function ($file) {
            return str($file)->startsWith('index-') && str($file)->endsWith('.css');
        })->first();
    }
}
