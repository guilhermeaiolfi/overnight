<?php
namespace ON\Container;

use Psr\Http\Server\MiddlewareInterface;
use ON\Router\ActionMiddlewareDecorator;

class MiddlewareFactory extends \Zend\Expressive\MiddlewareFactory
{
	public function prepare ($middleware): MiddlewareInterface
	{
		if (is_string($middleware) && strpos($middleware, "::") !== FALSE) {
			return new ActionMiddlewareDecorator($middleware);
		}
		return parent::prepare($middleware);
	}
}