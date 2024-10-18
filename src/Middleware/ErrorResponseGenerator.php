<?php

declare(strict_types=1);

namespace ON\Middleware;

use Laminas\Stratigility\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class ErrorResponseGenerator
{

    /**
     * @todo Allow nullable $layout
     */
    public function __construct(
        bool $isDevelopmentMode = false,
    ) {
        $this->debug    = $isDevelopmentMode;
    }

    public function __invoke(
        Throwable $e,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $response = $response->withStatus(Utils::getStatusCode($e, $response));
        return $this->prepareDefaultResponse($e, $this->debug, $response);
    }


      /**
     * Whether or not we are in debug/development mode.
     *
     * @var bool
     */
    private $debug;


    /** @var string */
    private $stackTraceTemplate = <<<'EOT'
%s raised in file %s line %d:
Message: %s
Stack Trace:
%s

EOT;

    private function prepareDefaultResponse(
        Throwable $e,
        bool $debug,
        ResponseInterface $response
    ): ResponseInterface {
        $message = 'An unexpected error occurred';

        if ($debug) {
            $message .= "; stack trace:\n\n" . $this->prepareStackTrace($e);
        }

        $response->getBody()->write($message);

        return $response;
    }

    /**
     * Prepares a stack trace to display.
     */
    private function prepareStackTrace(Throwable $e): string
    {
        $message = '';
        do {
            $message .= sprintf(
                $this->stackTraceTemplate,
                $e::class,
                $e->getFile(),
                $e->getLine(),
                $e->getMessage(),
                $e->getTraceAsString()
            );
        } while ($e = $e->getPrevious());

        return $message;
    }
}
