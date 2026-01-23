<?php

namespace Expose\Client\Http\Modifiers;

use Expose\Client\Configuration;
use Expose\Client\Logger\CliLogger;
use Expose\Client\Logger\RequestLogger;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Psr\Http\Message\RequestInterface;
use Ratchet\Client\WebSocket;

class CheckMagicAuthentication
{
    protected const COOKIE_NAME = 'expose_magic_auth';
    protected const LOGIN_PATH = '/__expose_magic_login';
    protected const COOKIE_LIFETIME = 86400 * 7;

    public function __construct(
        protected Configuration $configuration,
        protected CliLogger $cliLogger,
        protected RequestLogger $requestLogger
    ) {
    }

    public function handle(RequestInterface $request, ?WebSocket $proxyConnection): ?RequestInterface
    {
        if (!$this->requiresAuthentication() || is_null($proxyConnection)) {
            return $request;
        }

        if ($this->isLoginFormSubmission($request)) {
            return $this->handleLoginFormSubmission($request, $proxyConnection);
        }

        if ($this->hasValidAuthCookie($request)) {
            return $request;
        }

        return $this->showLoginForm($request, $proxyConnection);
    }

    protected function requiresAuthentication(): bool
    {
        return $this->configuration->magicAuth() !== null;
    }

    protected function isLoginFormSubmission(RequestInterface $request): bool
    {
        $uri = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        return $uri === self::LOGIN_PATH && $method === 'POST';
    }

    protected function handleLoginFormSubmission(RequestInterface $request, WebSocket $proxyConnection): ?RequestInterface
    {
        $body = (string) $request->getBody();
        parse_str($body, $formData);

        $email = trim($formData['email'] ?? '');
        $redirectUrl = $formData['redirect_url'] ?? '/';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->showLoginForm($request, $proxyConnection, 'Please enter a valid email address.', $redirectUrl);
        }

        if (!$this->isEmailAllowed($email)) {
            return $this->showLoginForm($request, $proxyConnection, 'This email address is not authorized to access this site.', $redirectUrl);
        }

        $cookieValue = $this->generateCookieValue($email);

        $response = new Response(302, [
            'Location' => $redirectUrl,
            'Set-Cookie' => self::COOKIE_NAME . '=' . $cookieValue . '; Path=/; Max-Age=' . self::COOKIE_LIFETIME . '; HttpOnly; SameSite=Lax',
        ]);

        $this->sendResponse($request, $proxyConnection, $response);

        return null;
    }

    protected function isEmailAllowed(string $email): bool
    {
        $patterns = $this->configuration->getAllowedMagicAuthPatterns();

        if (empty($patterns)) {
            return true;
        }

        $email = strtolower($email);

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));

            if (str_starts_with($pattern, '@')) {
                if (str_ends_with($email, $pattern)) {
                    return true;
                }
            } else {
                if ($email === $pattern) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function hasValidAuthCookie(RequestInterface $request): bool
    {
        $cookieValue = $this->getCookieValue($request, self::COOKIE_NAME);

        if (empty($cookieValue)) {
            return false;
        }

        return $this->validateCookieValue($cookieValue);
    }

    protected function getCookieValue(RequestInterface $request, string $name): ?string
    {
        $cookieHeader = Arr::get($request->getHeaders(), 'cookie.0', '');

        if (empty($cookieHeader)) {
            return null;
        }

        $cookies = [];
        foreach (explode(';', $cookieHeader) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies[$name] ?? null;
    }

    protected function generateCookieValue(string $email): string
    {
        $timestamp = time();
        $secret = $this->getSecret();
        $signature = hash_hmac('sha256', "{$email}|{$timestamp}", $secret);

        return base64_encode("{$email}|{$timestamp}|{$signature}");
    }

    protected function validateCookieValue(string $cookieValue): bool
    {
        $decoded = base64_decode($cookieValue);

        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return false;
        }

        [$email, $timestamp, $signature] = $parts;

        $secret = $this->getSecret();
        $expectedSignature = hash_hmac('sha256', "{$email}|{$timestamp}", $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        if ((time() - (int) $timestamp) > self::COOKIE_LIFETIME) {
            return false;
        }

        return true;
    }

    protected function getSecret(): string
    {
        return config('expose.magic-auth-secret-key');
    }

    protected function showLoginForm(RequestInterface $request, WebSocket $proxyConnection, ?string $error = null, ?string $redirectUrl = null): ?RequestInterface
    {
        $originalUrl = $redirectUrl ?? $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query) {
            $originalUrl .= '?' . $query;
        }

        $html = view('client.magic_login', [
            'error' => $error,
            'redirectUrl' => $originalUrl,
        ])->render();

        $response = new Response(401, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => strlen($html),
        ], $html);

        $this->sendResponse($request, $proxyConnection, $response);

        return null;
    }

    protected function sendResponse(RequestInterface $request, WebSocket $proxyConnection, Response $response): void
    {
        $rawResponse = Message::toString($response);

        if ($requestId = $this->getRequestId($request)) {
            $this->requestLogger->logResponseById($requestId, $rawResponse);
        }

        $proxyConnection->send($rawResponse);
        $proxyConnection->close();
    }

    protected function getRequestId(RequestInterface $request): ?string
    {
        $headers = $request->getHeader('x-expose-request-id');
        return $headers[0] ?? null;
    }
}
