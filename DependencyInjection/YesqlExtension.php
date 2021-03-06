<?php

namespace Ox\YesqlBundle\DependencyInjection;

use Ox\YesqlBundle\YesqlDumper;
use Ox\YesqlBundle\YesqlParser;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class YesqlExtension extends Extension
{

    private $parser;
    private $dumper;

    public function __construct()
    {
        $this->dumper = new YesqlDumper();
        $this->parser = new YesqlParser();
    }

    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $defaultConnection = $config['connection'];

        $names = array_column($config['services'], 'name');

        if (count($names) != count(array_unique($names))) {
            throw new InvalidConfigurationException('Service names are not unique.');
        }

        foreach ($config['services'] as $service) {
            list($class, $path) = $this->createClass($service['path'], $service['name'], $container);
            $connection = $service['connection'] ?? $defaultConnection;

            $definition = new DefinitionDecorator('yesql');
            $definition->setClass($class);
            $definition->replaceArgument(0, $path);
            $definition->replaceArgument(1, new Reference(sprintf('doctrine.dbal.%s_connection', $connection)));
            $definition->setPublic(true);

            $container->setDefinition(sprintf('yesql.%s', $service['name']), $definition);
        }
    }

    public function createClass($file, $name, ContainerBuilder $container)
    {
        $cacheDir = $container->getParameter('kernel.cache_dir');
        $container->addResource(new FileResource($file));

        $class = sprintf('Yesql%s', $this->toCamelCase($name));

        $factory = new ConfigCacheFactory(true);
        $cache = $factory->cache(
            sprintf('%s/%s.php', $cacheDir, $class),
            function (ConfigCacheInterface $cache) use ($file, $class) {
                $cache->write(
                    $this->dumper->dump(
                        $this->parser->parse($file),
                        $class),
                    [new FileResource($file)]);
            }
        );

        return [$class, $cache->getPath()];
    }

    private function toCamelCase($string)
    {
        return ucfirst(preg_replace_callback('/(_|\.)([a-z])/', function($matches) {
            return strtoupper($matches[2]);
        }, $string));
    }
}
