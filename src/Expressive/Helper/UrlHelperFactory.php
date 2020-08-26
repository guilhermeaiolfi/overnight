<?php
namespace ON\Expressive\Helper;

use Psr\Container\ContainerInterface;
use Mezzio\Router\RouterInterface;
use Mezzio\Helper\UrlHelper;

class UrlHelperFactory
{
    /**
     * Create a UrlHelper instance.
     *
     * @param ContainerInterface $container
     * @return UrlHelper
     * @throws Exception\MissingRouterException
     */
    public function __invoke(ContainerInterface $container)
    {
        if (! $container->has(RouterInterface::class)) {
            throw new Exception\MissingRouterException(sprintf(
                '%s requires a %s implementation; none found in container',
                UrlHelper::class,
                RouterInterface::class
            ));
        }
        $urlHelper = new UrlHelper($container->get(RouterInterface::class));
        $config = $container->get('config');
        $urlHelper->setBasePath($config["paths"]["basepath"]);
        return $urlHelper;
    }
}