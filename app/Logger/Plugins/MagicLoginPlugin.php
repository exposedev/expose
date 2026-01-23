<?php

namespace Expose\Client\Logger\Plugins;

class MagicLoginPlugin extends BasePlugin
{
    public function getTitle(): string
    {
        return 'Magic Login';
    }

    public function matchesRequest(): bool
    {
        $request = $this->loggedRequest->getRequest();
        $uri = $request->getUriString();
        $method = $request->getMethod();

        return str_contains($uri, '/__expose_magic_login') && strtoupper($method) === 'POST';
    }

    public function getPluginData(): PluginData
    {
        try {
            $content = $this->loggedRequest->getRequest()->getContent();
            parse_str($content, $formData);

            $email = $formData['email'] ?? 'Unknown';

            return PluginData::make()
                ->setPlugin($this->getTitle())
                ->setLabel($email)
                ->setDetails([
                    'email' => $email,
                    'type' => 'magic_login',
                ]);
        } catch (\Throwable $e) {
            return PluginData::error($this->getTitle(), $e);
        }
    }
}
