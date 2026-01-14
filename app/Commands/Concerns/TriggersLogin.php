<?php

namespace Expose\Client\Commands\Concerns;

use Expose\Client\Commands\SetupExposeProToken;
use Expose\Client\Support\TokenNodeVisitor;
use Illuminate\Support\Facades\Http;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;

use function Expose\Common\error;
use function Expose\Common\info;
use function Expose\Common\success;
use function Laravel\Prompts\spin;

trait TriggersLogin
{
    protected function triggerLogin(): bool
    {
        $platformUrl = config('expose.platform_url', 'https://expose.dev');
        $apiEndpoint = rtrim($platformUrl, '/') . '/api/client/device-auth/';

        info("If you don't have an Expose account yet, you can create one for free at <a href='https://expose.dev'>expose.dev</a>.");
        info();
        info("Opening your browser to login or create an account...");

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->post($apiEndpoint . 'create');

            if (! $response->ok()) {
                error('Failed to connect to the Expose platform. Please try again.');
                return false;
            }

            $data = $response->json();
            $deviceCode = $data['device_code'] ?? null;

            if (! $deviceCode) {
                error('Failed to connect to the Expose platform. Please try again.');
                return false;
            }
        } catch (\Exception $e) {
            error('Failed to connect to the Expose platform. Please check your internet connection.');
            return false;
        }

        // Open browser
        $loginUrl = rtrim($platformUrl, '/') . '/cli/login?device_code=' . $deviceCode;

        $this->openBrowser($loginUrl);

        info();
        info("If the browser doesn't open automatically, visit:");
        info("<a href='$loginUrl'>$loginUrl</a>");
        info();

        // Poll for completion
        $token = $this->pollForAuthentication($apiEndpoint, $deviceCode);

        if (! $token) {
            error('Authentication timed out or was cancelled. Please try again.');
            return false;
        }

        // Store the token
        $this->storeToken($token);

        info();
        success("You're all set! Your Expose account has been connected.");
        info();

        // Run pro setup if applicable
        (new SetupExposeProToken)($token);

        return true;
    }

    protected function pollForAuthentication(string $apiEndpoint, string $deviceCode): ?string
    {
        $maxAttempts = 150; // 5 minutes at 2 second intervals
        $attempt = 0;

        return spin(
            callback: function () use ($apiEndpoint, $deviceCode, $maxAttempts, &$attempt) {
                while ($attempt < $maxAttempts) {
                    $attempt++;

                    try {
                        $response = Http::withHeaders([
                            'Accept' => 'application/json',
                        ])->post($apiEndpoint . 'status', [
                            'device_code' => $deviceCode,
                        ]);

                        if ($response->ok()) {
                            $data = $response->json();
                            $status = $data['status'] ?? 'pending';

                            if ($status === 'completed') {
                                return $data['token'] ?? null;
                            }

                            if ($status === 'expired') {
                                return null;
                            }
                        }
                    } catch (\Exception $e) {
                        // Continue polling
                    }

                    sleep(2);
                }

                return null;
            },
            message: 'Waiting for authentication...'
        );
    }

    protected function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        exec("$command " . escapeshellarg($url) . " > /dev/null 2>&1 &");
    }

    protected function storeToken(string $token): void
    {
        $configFile = implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'],
            '.expose',
            'config.php',
        ]);

        if (! file_exists($configFile)) {
            @mkdir(dirname($configFile), 0777, true);
            $updatedConfigFile = $this->modifyConfigurationFileForToken(base_path('config/expose.php'), $token);
        } else {
            $updatedConfigFile = $this->modifyConfigurationFileForToken($configFile, $token);
        }

        file_put_contents($configFile, $updatedConfigFile);

        // Update the runtime config
        config(['expose.auth_token' => $token]);
    }

    protected function modifyConfigurationFileForToken(string $configFile, string $token): string
    {
        $lexer = new Emulative([
            'usedAttributes' => [
                'comments',
                'startLine',
                'endLine',
                'startTokenPos',
                'endTokenPos',
            ],
        ]);
        $parser = new Php7($lexer);

        $oldStmts = $parser->parse(file_get_contents($configFile));
        $oldTokens = $lexer->getTokens();

        $nodeTraverser = new NodeTraverser;
        $nodeTraverser->addVisitor(new CloningVisitor());
        $newStmts = $nodeTraverser->traverse($oldStmts);

        $nodeTraverser = new NodeTraverser;
        $nodeTraverser->addVisitor(new TokenNodeVisitor($token));

        $newStmts = $nodeTraverser->traverse($newStmts);

        $prettyPrinter = new Standard();

        return $prettyPrinter->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }

    protected function ensureExposeSetup(): void
    {
        if (empty(config('expose.auth_token'))) {
            if (! $this->triggerLogin()) {
                exit(1);
            }
        }
    }
}
