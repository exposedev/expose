<?php

namespace Expose\Client\Support;

class ExposeConfig
{
    public static function load(): void
    {
        $builtInConfig = config('expose') ?? [];

        $keyServerVariable = 'EXPOSE_CONFIG_FILE';
        if (array_key_exists($keyServerVariable, $_SERVER) && is_string($_SERVER[$keyServerVariable]) && file_exists($_SERVER[$keyServerVariable])) {
            $localConfig = require $_SERVER[$keyServerVariable];
            config()->set('expose', array_merge($builtInConfig, $localConfig));

            return;
        }

        $localConfigFile = getcwd().DIRECTORY_SEPARATOR.'.expose.php';

        if (file_exists($localConfigFile)) {
            $localConfig = require $localConfigFile;
            config()->set('expose', array_merge($builtInConfig, $localConfig));

            return;
        }

        $configFile = implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? __DIR__,
            '.expose',
            'config.php',
        ]);

        if (file_exists($configFile)) {
            $globalConfig = require $configFile;
            config()->set('expose', array_merge($builtInConfig, $globalConfig));
        }
    }
}
