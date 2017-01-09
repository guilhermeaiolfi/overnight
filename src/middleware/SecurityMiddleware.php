<?php
namespace ON\middleware;

class SecurityMiddleware
{
    private $user = null;

    public function __construct($user) {
        $this->user = $user;
    }

    public function __invoke($request, $response, $next)
    {
        $controller = $event->getController();
        $user = $this->user;
        if (is_array($controller)) {
            $controller = $controller[0];
        }
        if ($controller->isSecure() && ($user && !$user["authenticated"])) {
            throw new AccessDeniedHttpException('This action needs a valid token!');
            //return new Response('Hello world!');
        }
        return $next($request, $response);
    }

}
?>