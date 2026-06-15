<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Mutation\Compiler\HydrationInput;
use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Payload\MutationInputMerger;

/**
 * Merges uploaded files into the raw mutation payload before parsing.
 */
final class MergeMutationInput implements HydrationPassInterface
{
	private MutationInputMerger $merger;

	public function __construct(?MutationInputMerger $merger = null)
	{
		$this->merger = $merger ?? new MutationInputMerger();
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof HydrationInput || $subject->options->files === []) {
			return $subject;
		}

		return new HydrationInput(
			$subject->collection,
			$this->merger->mergeFiles($subject->collection, $subject->input, $subject->options->files),
			$subject->options,
		);
	}
}
