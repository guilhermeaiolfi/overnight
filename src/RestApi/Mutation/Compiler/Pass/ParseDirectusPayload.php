<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Mutation\Compiler\HydrationInput;
use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;
use ON\RestApi\Payload\Parser\PayloadParserInterface;

/**
 * Parses raw Directus-style payload input into the initial record node.
 */
final class ParseDirectusPayload implements HydrationPassInterface
{
	public function __construct(
		private readonly ?PayloadParserInterface $parser = null,
	) {
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if ($subject instanceof RecordNode) {
			return $subject;
		}

		if (! $subject instanceof HydrationInput) {
			throw new \InvalidArgumentException('ParseDirectusPayload requires mutation compiler input.');
		}

		return $this->parser()->parse(
			$subject->collection,
			$subject->input,
		);
	}

	private function parser(): PayloadParserInterface
	{
		return $this->parser ?? new DirectusPayloadParser();
	}
}
