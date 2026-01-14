<?php

namespace Expose\Client\Commands;


use Expose\Client\Contracts\FetchesPlatformDataContract;
use Expose\Client\Traits\FetchesPlatformData;

use Illuminate\Support\Facades\Artisan;

use function Expose\Common\warning;
use function Expose\Common\info;
use function Expose\Common\headline;
use function Laravel\Prompts\select;

class SetUpExposeDefaultDomain implements FetchesPlatformDataContract
{

    use FetchesPlatformData;

    protected string $token;

    public function __invoke(string $token)
    {
        if (!$this->exposePlatformSetup()) return;

        $this->token = $token;

        headline('Default Domain');
        info('Use your teams custom whitelabel domains for easier subdomain management and better links.');

        if ($this->isProToken()) {
            $domains = $this->getTeamDomains();

            if ($domains->isNotEmpty()) {
                info();

                $options = $domains->mapWithKeys(function ($domain) {
                    return [
                        $domain['name'] =>  $domain['name'] . ' [' . $domain['server'] . ']'
                    ];
                })->prepend('None (can be changed later)', '__none__');

                $domain = select(
                    label: 'What default domain would you like to use?',
                    options: $options,
                    hint: "You can use `expose default-domain` to change this setting."
                );

                if ($domain === '__none__') {
                    Artisan::call("default-domain:clear");
                } elseif ($domain) {
                    $server = $domains->firstWhere('name', $domain)['server'];
                    Artisan::call("default-domain $domain --server=$server");
                }
            }

            else {
                warning('No custom domains found. You can add custom domains in the Expose dashboard.');
            }
        }
        else {
            info('To access to custom domains, upgrade to <a href="https://expose.dev">Expose Pro</a>.');
        }
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
