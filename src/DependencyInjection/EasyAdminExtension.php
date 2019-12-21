<?php

namespace EasyCorp\Bundle\EasyAdminBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Resolves all the backend configuration values and most of the entities
 * configuration. The information that must resolved during runtime is handled
 * by the Configurator class.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class EasyAdminExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        // process bundle's configuration parameters
        $configs = $this->processConfigFiles($configs);
        $backendConfig = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('easyadmin.config', $backendConfig);

        // TODO: remove the loading of legacy services
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');
        $loader->load('commands.xml');
        $loader->load('form.xml');

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');


        if ($container->getParameter('kernel.debug')) {
            // in 'dev', use the built-in Symfony exception listener
            $container->removeDefinition('easyadmin.listener.exception');
        }

        /*
        if ($container->hasParameter('locale')) {
            $container->getDefinition('easyadmin.configuration.design_config_pass')
                ->replaceArgument(0, $container->getParameter('locale'));
        }
        */
    }

    /**
     * This method allows to define the entity configuration is several files.
     * Without this, Symfony doesn't merge correctly the 'entities' config key
     * defined in different files.
     *
     * @param array $configs
     *
     * @return array
     */
    private function processConfigFiles(array $configs)
    {
        $existingEntityNames = [];

        foreach ($configs as $i => $config) {
            if (\array_key_exists('entities', $config)) {
                $processedConfig = [];

                foreach ($config['entities'] as $key => $value) {
                    $entityConfig = $this->normalizeEntityConfig($key, $value);
                    $entityName = $this->getUniqueEntityName($key, $entityConfig, $existingEntityNames);
                    $entityConfig['name'] = $entityName;

                    $processedConfig[$entityName] = $entityConfig;

                    $existingEntityNames[] = $entityName;
                }

                $config['entities'] = $processedConfig;
            }

            $configs[$i] = $config;
        }

        return $configs;
    }

    /**
     * Transforms the two simple configuration formats into the full expanded
     * configuration. This allows to reuse the same method to process any of the
     * different configuration formats.
     *
     * These are the two simple formats allowed:
     *
     * # Config format #1: no custom entity name
     * easy_admin:
     *     entities:
     *         - AppBundle\Entity\User
     *
     * # Config format #2: simple config with custom entity name
     * easy_admin:
     *     entities:
     *         User: AppBundle\Entity\User
     *
     * And this is the full expanded configuration syntax generated by this method:
     *
     * # Config format #3: expanded entity configuration with 'class' parameter
     * easy_admin:
     *     entities:
     *         User:
     *             class: AppBundle\Entity\User
     *
     * @param mixed $entityName
     * @param mixed $entityConfig
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    private function normalizeEntityConfig($entityName, $entityConfig)
    {
        // normalize config formats #1 and #2 to use the 'class' option as config format #3
        if (!\is_array($entityConfig)) {
            $entityConfig = ['class' => $entityConfig];
        }

        // if config format #3 is used, ensure that it defines the 'class' option
        if (!isset($entityConfig['class'])) {
            throw new \RuntimeException(sprintf('The "%s" entity must define its associated Doctrine entity class using the "class" option.', $entityName));
        }

        return $entityConfig;
    }

    /**
     * The name of the entity is included in the URLs of the backend to define
     * the entity used to perform the operations. Obviously, the entity name
     * must be unique to identify entities unequivocally.
     *
     * This method ensures that the given entity name is unique among all the
     * previously existing entities passed as the second argument. This is
     * achieved by iteratively appending a suffix until the entity name is
     * guaranteed to be unique.
     *
     * @param string $entityName
     * @param array  $entityConfig
     * @param array  $existingEntityNames
     *
     * @return string The entity name transformed to be unique
     */
    private function getUniqueEntityName($entityName, array $entityConfig, array $existingEntityNames)
    {
        // the shortcut config syntax doesn't require to give entities a name
        if (is_numeric($entityName)) {
            $entityClassParts = explode('\\', $entityConfig['class']);
            $entityName = end($entityClassParts);
        }

        $i = 2;
        $uniqueName = $entityName;
        while (\in_array($uniqueName, $existingEntityNames)) {
            $uniqueName = $entityName.($i++);
        }

        $entityName = $uniqueName;

        // make sure that the entity name is valid as a PHP method name
        // (this is required to allow extending the backend with a custom controller)
        if (!$this->isValidMethodName($entityName)) {
            throw new \InvalidArgumentException(sprintf('The name of the "%s" entity contains invalid characters (allowed: letters, numbers, underscores; the first character cannot be a number).', $entityName));
        }

        return $entityName;
    }

    /**
     * Checks whether the given string is valid as a PHP method name.
     */
    private function isValidMethodName(string $name): bool
    {
        return 0 !== preg_match('/^-?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name);
    }
}
