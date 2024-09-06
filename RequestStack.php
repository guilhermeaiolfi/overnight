<?php
// Copied from symfony

use Psr\Http\Message\RequestInterface;

class RequestStack
{
    /**
     * @var RequestInterface[]
     */
    private array $requests = [];

    /**
     * @param RequestInterface[] $requests
     */
    public function __construct(array $requests = [])
    {
        foreach ($requests as $request) {
            $this->push($request);
        }
    }

    /**
     * Pushes a Request on the stack.
     *
     * This method should generally not be called directly as the stack
     * management should be taken care of by the application itself.
     */
    public function push(RequestInterFace $request): void
    {
        $this->requests[] = $request;
    }

    /**
     * Pops the current request from the stack.
     *
     * This operation lets the current request go out of scope.
     *
     * This method should generally not be called directly as the stack
     * management should be taken care of by the application itself.
     */
    public function pop(): ?RequestInterface
    {
        if (!$this->requests) {
            return null;
        }

        return array_pop($this->requests);
    }

    public function getCurrentRequest(): ?RequestInterface
    {
        return end($this->requests) ?: null;
    }

    /**
     * Gets the main request.
     *
     * Be warned that making your code aware of the main request
     * might make it un-compatible with other features of your framework
     * like ESI support.
     */
    public function getMainRequest(): ?RequestInterface
    {
        if (!$this->requests) {
            return null;
        }

        return $this->requests[0];
    }

    /**
     * Returns the parent request of the current.
     *
     * Be warned that making your code aware of the parent request
     * might make it un-compatible with other features of your framework
     * like ESI support.
     *
     * If current Request is the main request, it returns null.
     */
    public function getParentRequest(): ?RequestInterface
    {
        $pos = \count($this->requests) - 2;

        return $this->requests[$pos] ?? null;
    }
}