<?php
/**
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2015-2019 Dominik Pfaffenbauer (https://www.pfaffenbauer.at)
 * @license    https://www.coreshop.org/license     GNU General Public License version 3 (GPLv3)
 */

namespace CoreShop\Bundle\StoreBundle\DependencyInjection;

use CoreShop\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractModelExtension;
use CoreShop\Bundle\StoreBundle\DependencyInjection\Compiler\CompositeRequestResolverPass;
use CoreShop\Bundle\StoreBundle\DependencyInjection\Compiler\CompositeStoreContextPass;
use CoreShop\Component\Store\Context\RequestBased\RequestResolverInterface;
use CoreShop\Component\Store\Context\StoreContextInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CoreShopStoreExtension extends AbstractModelExtension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $config, ContainerBuilder $container)
    {
        $config = $this->processConfiguration($this->getConfiguration([], $container), $config);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $this->registerResources('coreshop', $config['driver'], $config['resources'], $container);

        if (array_key_exists('pimcore_admin', $config)) {
            $this->registerPimcoreResources('coreshop', $config['pimcore_admin'], $container);
        }

        $loader->load('services.yml');

        $container
            ->registerForAutoconfiguration(StoreContextInterface::class)
            ->addTag(CompositeStoreContextPass::STORE_CONTEXT_TAG)
        ;
        $container
            ->registerForAutoconfiguration(RequestResolverInterface::class)
            ->addTag(CompositeRequestResolverPass::STORE_REQUEST_RESOLVER_TAG)
        ;
    }
}
