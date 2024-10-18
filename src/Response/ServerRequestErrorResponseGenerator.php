<?php

declare(strict_types=1);

namespace ON\Response;

use Laminas\Stratigility\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function is_callable;

/**
 * Generates a response for use when the server request factory fails.
 */
class ServerRequestErrorResponseGenerator
{
    private ResponseFactoryInterface $responseFactory;

    /**
     * @param (callable():ResponseInterface)|ResponseFactoryInterface $responseFactory
     */
    public function __construct(
        $responseFactory,
        bool $isDevelopmentMode = false
    ) {
        $this->responseFactory = $responseFactory;
        $this->debug    = $isDevelopmentMode;
    }

    public function __invoke(Throwable $e): ResponseInterface
    {
        $response = $this->responseFactory->createResponse();
        $response = $response->withStatus(Utils::getStatusCode($e, $response));

        return $this->prepareDefaultResponse($e, $this->debug, $response);
    }

    public function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

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
        dd("this message is coming from ServerRequestErrorResponseGenerator class");
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
