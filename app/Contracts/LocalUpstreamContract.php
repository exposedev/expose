<?php

namespace Expose\Client\Contracts;

use Psr\Http\Message\RequestInterface;

/**
 * A local upstream behind the shared hostname other than the application
 * itself (e.g. a Vite dev server, the Herd Studio API). The HttpClient asks
 * each registered upstream - in order - whether it wants a request; the first
 * match receives it, everything else goes to the application.
 *
 * Upstreams are registered in the `expose.local-upstreams` container binding.
 */
interface LocalUpstreamContract
{
    /**
     * Whether this upstream should serve the given tunneled request.
     */
    public function shouldHandle(RequestInterface $request): bool;

    /**
     * Point the request at this upstream (URI, Host header, ...).
     */
    public function rewriteRequest(RequestInterface $request): RequestInterface;
}
