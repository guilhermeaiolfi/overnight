<?php
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace ON\eventlisteners;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

class ViewListener implements EventSubscriberInterface
{
    private $controller = null;

    public function onKernelController(FilterControllerEvent $event)
    {
        $this->controller = $event->getController();
    }
    public function onKernelView(GetResponseForControllerResultEvent $event)
    {
        $request    = $event->getRequest();
        $page = $this->controller[0];
        $parameters = $event->getControllerResult();

        $view_method = $parameters;
        $view = $page;
        if (strpos($view_method, ":") !== FALSE) {
          $view_method = explode(":", $view_method);
          $view = $this->application->getInjector()->make($view_method[0] . 'Page');
          $view_method = $view_method[1];
          $view->setAttributes($page->getAttributes());
        }

        $content = $view->{$view_method . 'View'}($request);

        $response = new Response();
        $response->setContent($content);
        //$this->addNoCacheHeaders($response);
        $event->setResponse($response);

    }
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::VIEW  => array(array('onKernelView', 100)),
            KernelEvents::CONTROLLER  => array(array('onKernelController', 0)),
        );
    }
}