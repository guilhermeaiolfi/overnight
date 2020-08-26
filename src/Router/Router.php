<?php

namespace ON\Router;

use Aura\Router\RouterContainer;
use Mezzio\Router\AuraRouter;
use Aura\Router\Route as AuraRoute;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Psr\Http\Message\ServerRequestInterface as Request;
use ON\Router\RouterInterface as ONRouterInterface;
use Mezzio\Router\RouterInterface as ZendRouterInterface;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\ServerRequestFactory;
use ON\Context;
use Mezzio\Router\Exception;
use Mezzio\Router\RouterInterface;

class Router implements StatefulRouterInterface {

  const FRAGMENT_IDENTIFIER_REGEX = '/^([!$&\'()*+,;=._~:@\/?-]|%[0-9a-fA-F]{2}|[a-zA-Z0-9])+$/';

  protected $basepath = "";

  protected $routed_stack = [];

  protected $router;

  protected $context;
  /**
   * Constructor
   *
   * If no Aura.Router instance is provided, the constructor will lazy-load
   * an instance. If you need to customize the Aura.Router instance in any
   * way, you MUST inject it yourself.
   *
   * @param null|Router $router
   */
  public function __construct(RouterInterface $router = null, $basepath = null, Context $context = null)
  {
    if ($router == null) {
      $this->router = new AuraRouter();
    } else {
      $this->router = $router;
    }

    $this->context = $context;
    $this->basepath = $basepath;
  }

  public function getRouteResult ($index) {
    return isset($this->routed_stack[$index])? $this->routed_stack[$index] : null;
  }

  public function addRouteResult ($route) {
    $this->routed_stack[] = $route;
  }

  public function getFirstRouteResult () {
    return $this->getRouteResult(0);
  }

  public function getLastRouteResult () {
    return $this->getRouteResult(count($this->routed_stack) - 1);
  }

  static public function detectBaseUrl ($request = null) {
    if ($request == null) {
      $request = ServerRequestFactory::fromGlobals();
    }
    $server = $request->getServerParams();

    // Path
    $requestScriptName = parse_url($server['SCRIPT_NAME'], PHP_URL_PATH);
    $requestScriptDir = dirname($requestScriptName);

    // parse_url() requires a full URL. As we don't extract the domain name or scheme,
    // we use a stand-in.
    $requestUri = parse_url('http://example.com' . $server['REQUEST_URI'], PHP_URL_PATH);

    $requestRootDir = dirname($requestScriptDir); // remove /public from it

    $basePath = '';
    $virtualPath = $requestUri;
    //var_dump($requestUri, $requestScriptName, $requestScriptDir, $requestRootDir);
    if (stripos($requestUri, $requestRootDir) === 0) {
        $basePath = $requestRootDir;
    } elseif ($requestScriptDir !== '/' && stripos($requestUri, $requestRootDir) === 0) {
        $basePath = $requestScriptDir;
    }
    if ($basePath) {
        $virtualPath = ltrim(substr($requestUri, strlen($basePath)), '/');
    }

    //$basePath = str_replace('\\','/',substr(getcwd(),strlen($server['DOCUMENT_ROOT'])));
    return ltrim($basePath, '/');
  }

  public function gen($routeName = null, $routeParams = [], $options = []) {
    $default_opts = [
      "relative" => true,
      "fragment" => null,
      "absolute" => false,
      "port"    =>  80,
      "scheme"  => "http"
    ];

    $basepath = $this->getBasePath();

    $options = array_merge($default_opts, $options);

    $result = $this->getFirstRouteResult();

    $request = $this->context->getAttribute("REQUEST");

    $uri = $request->getUri();

    if ($routeName === null && $result === null) {
        // get current URL
        throw new Exception\RuntimeException(
            'Attempting to use matched result when none was injected; aborting'
        );
    }

    // Get the options to be passed to the router
    $routerOptions = array_key_exists('router', $options) ? $options['router'] : [];

    if ($routeName === null) {
      if ($result->isFailure()) {
        throw new Exception\RuntimeException(
          'Attempting to use matched result when routing failed; aborting'
        );
      }
      $name   = $result->getMatchedRouteName();

      $params = $result->getMatchedParams();

      $queryParams = array_diff_key($routeParams, $params);

      $params = array_merge($request->getQueryParams(), $params, $routeParams);

      return $this->gen($name, $params, $options);
    }


    $reuseResultParams = ! isset($options['reuse_result_params']) || (bool) $options['reuse_result_params'];

    $params = [];
    if ($result && $reuseResultParams) {
      // Merge RouteResult with the route parameters
      $params = $this->mergeParams($routeName, $result, $routeParams);
    }

    try {
      // Generate the route
      $path = $this->generateUri($routeName, $routeParams, $routerOptions);
      $result = $this->match(new ServerRequest([], [], $path));
      $params = $result->getMatchedParams();
    } catch (\Exception $e) {
      $path = $this->getBasePath() . "/" . $routeName;
      $params = [];
    }

    $uri = $request->getUri()->withPath($path);

    $queryParams = array_diff_key($routeParams, $params);

    // Append query parameters if there are any
    if (count($queryParams) > 0) {
      //$path .= '?' . http_build_query($queryParams);
      $uri = $uri->withQuery(http_build_query($queryParams));
    } else {
      $uri = $uri->withQuery('');
    }

    // Append the fragment identifier
    if ($options["fragment"] !== null) {
      if (! preg_match(self::FRAGMENT_IDENTIFIER_REGEX, $options["fragment"])) {
        throw new InvalidArgumentException('Fragment identifier must conform to RFC 3986', 400);
      }
      //$path .= '#' . $options["fragment"];
      $uri = $uri->withFragment($options["fragment"]);
    }


    if (!$options["absolute"]) {
      $uri = $uri->withHost("");
    }
    if ($options["scheme"] != "http") {
      $uri = $uri->withScheme($options["scheme"]);
    } else {
      if ($options["absolute"]) {
        $uri = $uri->withScheme('http');
      } else {
        $uri = $uri->withScheme('');
      }
    }
    if ($options["port"] != 80) {
      $uri = $uri->withPort($options["port"]);
    } else {
      $uri = $uri->withPort(80);
    }

    $uri = (string) $uri;

    return $uri;


  }

  protected function mergeParams($route, RouteResult $result, array $params)
  {
    if ($result->isFailure()) {
      return $params;
    }

    if ($result->getMatchedRouteName() !== $route) {
      return $params;
    }

    return array_merge($result->getMatchedParams(), $params);
  }

  public function getBasePath() {
    return $this->basepath;
  }

  public function match(Request $request): \Mezzio\Router\RouteResult
  {
    return $this->router->match($request);
  }

  public function generateUri($name, array $substitutions = [], array $options = []): string
  {
    return $this->router->generateUri($name, $substitutions, $options);
  }

  public function addRoute(Route $route): void
  {
      $this->router->addRoute($route);
  }

}