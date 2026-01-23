<?php

namespace Expose\Client;

use Illuminate\Support\Str;

class Configuration
{
    /** @var string */
    protected $host;

    /** @var string */
    protected $serverHost;

    /** @var int */
    protected $port;

    /** @var string|null */
    protected $auth;

    /** @var string|null */
    protected $basicAuth;

    /** @var string|null */
    protected $magicAuth;

    /** @var bool */
    protected $isSecureSharedUrl = false;

    /** @var bool */
    protected $preventCORS = false;

    public function __construct(string $host, int $port, ?string $auth = null, ?string $basicAuth = null, bool $preventCORS = false, ?string $magicAuth = null)
    {
        $this->serverHost = $this->host = $host;

        $this->port = $port;

        $this->auth = $auth;

        $this->basicAuth = $basicAuth;

        $this->magicAuth = $magicAuth;

        $this->preventCORS = $preventCORS;

        if ($this->magicAuth !== null) {
            config(['expose.magic-auth-secret-key' => Str::random(32)]);
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function serverHost(): string
    {
        return $this->serverHost;
    }

    public function setServerHost($serverHost)
    {
        $this->serverHost = $serverHost;
    }

    public function auth(): ?string
    {
        return $this->auth;
    }

    public function basicAuth(): ?string
    {
        return $this->basicAuth;
    }

    public function magicAuth(): ?string
    {
        return $this->magicAuth;
    }

    public function getAllowedMagicAuthPatterns(): array
    {
        if (is_null($this->magicAuth) || $this->magicAuth === '') {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $this->magicAuth)));
    }

    public function preventCORS(): bool
    {
        return $this->preventCORS;
    }

    public function port(): int
    {
        return intval($this->port);
    }

    public function getUrl(string $subdomain): string
    {
        $httpProtocol = $this->port() === 443 ? 'https' : 'http';
        $host = $this->serverHost();

        if ($httpProtocol !== 'https') {
            $host .= ":{$this->port()}";
        }

        return "{$subdomain}.{$host}";
    }

    public function isSecureSharedUrl(): bool
    {
        return $this->isSecureSharedUrl;
    }

    public function setIsSecureSharedUrl($value)
    {
        $this->isSecureSharedUrl = $value;
    }
}
