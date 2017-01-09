<?php

namespace ON\middleware;

class ExceptionMiddleware
{
    private $user = null;
    private $app = null;

    public function __construct($app) {
        $this->app = $app;
    }

    public function __invoke ($request, $response, $next)
    {
        /*$app = $this->app;
        if ($event->getException() instanceof AccessDeniedHttpException) {
            $event->setResponse(new RedirectResponse($app->router->generate("login")));
        }
        else if ($event->getException() instanceof NotFoundHttpException) {
            $response = new Response();
            $response->setStatusCode(404);
            $response->setContent("Not found");
            $event->setResponse($response);
        }*/
        return $next($request, $response);
    }
}
?>