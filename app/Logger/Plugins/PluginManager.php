<?php

namespace Expose\Client\Logger\Plugins;

use Expose\Client\Logger\LoggedRequest;
use Expose\Client\Support\InsertRequestPluginsNodeVisitor;
use Expose\Client\Support\RequestPluginsNodeVisitor;
use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class PluginManager
{
    protected ?array $defaultPlugins = null;
    protected ?array $customPlugins = null;

    protected ?array $pluginConfig = null;

    public function __construct()
    {
        $this->loadDefaultPlugins();
        $this->loadCustomPlugins();
        $this->ensureValidPluginConfig();
    }

    public function getDefaultPlugins(): array
    {
        return $this->defaultPlugins;
    }

    public function getCustomPlugins(): array
    {
        return $this->customPlugins;
    }

    public function getPlugins(): array
    {
        return array_merge($this->defaultPlugins, $this->customPlugins);
    }

    public function loadPluginData(LoggedRequest $loggedRequest): ?PluginData
    {
        foreach ($this->pluginConfig as $pluginClass) {
            try {
                $plugin = $pluginClass::make($loggedRequest);

                if ($plugin->matchesRequest()) {
                    return $plugin->getPluginData();
                }
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    public function getEnabledPlugins(): array
    {
        return $this->pluginConfig;
    }

    public function isEnabled(string $pluginClass): bool
    {
        return in_array($pluginClass, $this->pluginConfig);
    }

    protected function ensureValidPluginConfig(): void
    {
        $this->pluginConfig = config('expose.request_plugins', []);

        foreach ($this->pluginConfig as $pluginClass) {
            // Remove invalid plugins from the configuration
            if (!class_exists($pluginClass) || !is_subclass_of($pluginClass, BasePlugin::class)) {
                $this->pluginConfig = array_diff($this->pluginConfig, [$pluginClass]);
            }
        }
    }

    protected function loadCustomPlugins(): array
    {
        $pluginDirectory = $this->getCustomPluginDirectory();

        $this->customPlugins = [];

        if (!is_dir($pluginDirectory)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($pluginDirectory)
            ->name('*.php')
            ->contains('Expose\Client\Logger\Plugins\BasePlugin')
            ->filter(function (SplFileInfo $file) {
                require_once $file->getPathname();  // required because autoloader won't be able to find this file in the normal path based on the namespace

                $pluginClass = 'Expose\Client\Logger\Plugins\\' . $file->getFilenameWithoutExtension();

                if (!class_exists($pluginClass) || !is_subclass_of($pluginClass, BasePlugin::class)) {
                    print $file . "\n";
                    return false;
                }

                return true;
            })
            ->sortByName()
        ;

        foreach ($finder as $file) {
            $this->customPlugins[] = 'Expose\Client\Logger\Plugins\\' . $file->getFilenameWithoutExtension();
        }

        return $this->customPlugins;
    }

    protected function loadDefaultPlugins(): array
    {
        $this->defaultPlugins = [];

        $finder = (new Finder())
            ->files()
            ->in($this->getDefaultPluginDirectory())
            ->name('*.php')
            ->filter(function (SplFileInfo $file) {
                $pluginClass = 'Expose\Client\Logger\Plugins\\' . $file->getFilenameWithoutExtension();

                if (!class_exists($pluginClass) || !is_subclass_of($pluginClass, BasePlugin::class)) {
                    return false;
                }

                return true;
            })
            ->sortByName()
        ;

        foreach ($finder as $file) {
            $this->defaultPlugins[] = 'Expose\Client\Logger\Plugins\\' . $file->getFilenameWithoutExtension();
        }

        return $this->defaultPlugins;
    }

    protected function getDefaultPluginDirectory(): string
    {
        return __DIR__ . '/../Plugins';
    }

    public function getCustomPluginDirectory(): string
    {
        $path = implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? __DIR__,
            '.expose',
            'plugins'
        ]);

        if (!file_exists($path)) {
            @mkdir($path, 0755, true);
        }

        return $path;
    }

    public function modifyPluginConfiguration(array $pluginsToEnable): void
    {
        $configFile = implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'],
            '.expose',
            'config.php',
        ]);

        if (!file_exists($configFile)) {
            @mkdir(dirname($configFile), 0777, true);
            $updatedConfigFile = $this->writePluginConfig(base_path('config/expose.php'), $pluginsToEnable);
        } else {
            $updatedConfigFile = $this->writePluginConfig($configFile, $pluginsToEnable);
        }

        file_put_contents($configFile, $updatedConfigFile);
    }

    protected function writePluginConfig(string $configFile, array $pluginsToEnable)
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

        $nodeFinder = new NodeFinder;

        $requestPluginsNode = $nodeFinder->findFirst($newStmts, function (Node $node) {
            return $node instanceof Node\Expr\ArrayItem && $node->key && $node->key->value === 'request_plugins';
        });

        if (is_null($requestPluginsNode)) {
            $nodeTraverser = new NodeTraverser;
            $nodeTraverser->addVisitor(new InsertRequestPluginsNodeVisitor());
            $newStmts = $nodeTraverser->traverse($newStmts);
        }

        $nodeTraverser = new NodeTraverser;
        $nodeTraverser->addVisitor(new RequestPluginsNodeVisitor($pluginsToEnable));

        $newStmts = $nodeTraverser->traverse($newStmts);

        $prettyPrinter = new Standard();

        return $prettyPrinter->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }
}
