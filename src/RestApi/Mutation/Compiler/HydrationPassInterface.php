<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler;

/**
 * A single compiler step that transforms one mutation compiler subject into the next.
 */
interface HydrationPassInterface
{
	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface;
}
