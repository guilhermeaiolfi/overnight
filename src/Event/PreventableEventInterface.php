<?php

declare(strict_types=1);

namespace ON\Event;

/**
 * Interface for events that can prevent the default action.
 *
 * Follows the browser DOM event model: listeners call preventDefault()
 * to skip the default action. The dispatcher checks isDefaultPrevented()
 * after all listeners have run.
 */
interface PreventableEventInterface
{
	public function preventDefault(): void;

	public function isDefaultPrevented(): bool;
}
