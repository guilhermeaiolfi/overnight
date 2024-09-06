<?php

namespace ON;

use Psr\Http\Message\ServerRequestInterface;

class RequestStack
{
    /**
     * @var ServerRequestInterface[]
     */
    private array $requests = [];

    /**
     * @param ServerRequestInterface[] $requests
     */
    public function __construct(array $requests = [])
    {
        foreach ($requests as $request) {
            $this->push($request);
        }
    }

    public function isMainRequest(ServerRequestInterface $request): bool {
        return $this->getMainRequest() == $request;
    }

    public function update(ServerRequestInterface $old, ServerRequestInterface $new) {
        $index = array_search($old, $this->requests);
        if ($index !== false) {
            $this->requests[$index] = $new;
        }
    }

    /**
     * Pushes a Request on the stack.
     *
     * This method should generally not be called directly as the stack
     * management should be taken care of by the application itself.
     */
    public function push(ServerRequestInterface $request): void
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
    public function pop(): ?ServerRequestInterface
    {
        if (!$this->requests) {
            return null;
        }

        return array_pop($this->requests);
    }

    public function getCurrentRequest(): ?ServerRequestInterface
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
    public function getMainRequest(): ?ServerRequestInterface
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
    public function getParentRequest(): ?ServerRequestInterface
    {
        $pos = \count($this->requests) - 2;

        return $this->requests[$pos] ?? null;
    }
}