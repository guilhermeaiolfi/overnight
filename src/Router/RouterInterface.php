<?php

namespace ON\Router;

use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface {
  public function gen($name = null, $params = [], $options = []);
  public function getBasePath();
  /**
   * Add a route.
   *
   * This method adds a route against which the underlying implementation may
   * match. Implementations MUST aggregate route instances, but MUST NOT use
   * the details to inject the underlying router until `match()` and/or
   * `generateUri()` is called.  This is required to allow consumers to
   * modify route instances before matching (e.g., to provide route options,
   * inject a name, etc.).
   */
  public function addRoute(Route $route): void;

  /**
   * Match a request against the known routes.
   *
   * Implementations will aggregate required information from the provided
   * request instance, and pass them to the underlying router implementation;
   * when done, they will then marshal a `RouteResult` instance indicating
   * the results of the matching operation and return it to the caller.
   */
  public function match(ServerRequestInterface $request): RouteResult;

  /**
   * Generate a URI from the named route.
   *
   * Takes the named route and any substitutions, and attempts to generate a
   * URI from it. Additional router-dependent options may be passed.
   *
   * The URI generated MUST NOT be escaped. If you wish to escape any part of
   * the URI, this should be performed afterwards; consider passing the URI
   * to league/uri to encode it.
   *
   * @see https://github.com/auraphp/Aura.Router/blob/3.x/docs/generating-paths.md
   * @see https://docs.laminas.dev/laminas-router/routing/
   *
   * @throws Exception\RuntimeException If unable to generate the given URI.
   */
  public function generateUri(string $name, array $substitutions = [], array $options = []): string;
}