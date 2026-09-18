<?php

namespace Expose\Client\Logger;

use Expose\Client\Contracts\LoggerContract;
use Expose\Client\Http\Resources\LogListResource;
use Expose\Client\WebSockets\Socket;
use React\Http\Browser;

class FrontendLogger implements LoggerContract
{

    public function __construct(protected Browser $browser)
    {
    }

    public function synchronizeRequest(LoggedRequest $loggedRequest): void
    {
        if (empty(Socket::$connections)) {
            return;
        }

        $this
            ->browser
            ->post(
                'http://127.0.0.1:'.config()->get('expose.dashboard_port').'/api/logs',
                ['Content-Type' => 'application/json'],
                json_encode(LogListResource::fromLoggedRequest($loggedRequest)->toArray(), JSON_INVALID_UTF8_IGNORE)
            );
    }

    public function synchronizeResponse(LoggedRequest $loggedRequest, LoggedResponse $loggedResponse): void
    {
        $this->synchronizeRequest($loggedRequest);
    }
}
