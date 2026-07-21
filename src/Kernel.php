<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private const CONFIG_EXTS = '.{php,yaml,yml}';

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader): void
    {
        $container->import('../config/{packages}/*'.self::CONFIG_EXTS);
        $container->import('../config/{packages}/'.$this->environment.'/*'.self::CONFIG_EXTS);
        $container->import('../config/{admin}/*'.self::CONFIG_EXTS);
        $container->import('../config/{datagrid}/*'.self::CONFIG_EXTS);
        $container->import('../config/{services}'.self::CONFIG_EXTS);
        $container->import('../config/{services}/*'.self::CONFIG_EXTS);
        $container->import('../config/{services}_'.$this->environment.self::CONFIG_EXTS);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('../config/{routes}/'.$this->environment.'/*'.self::CONFIG_EXTS);
        $routes->import('../config/{routes}/*'.self::CONFIG_EXTS);
        $routes->import('../config/{routes}'.self::CONFIG_EXTS);
    }
}
