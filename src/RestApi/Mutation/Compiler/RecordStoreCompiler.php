<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Mutation\Compiler\Pass\AttachMutationState;
use ON\RestApi\Mutation\Compiler\Pass\AttachRelationDefinitions;
use ON\RestApi\Mutation\Compiler\Pass\ApplyCycleRecordGraph;
use ON\RestApi\Mutation\Compiler\Pass\HydrateCycleRecords;
use ON\RestApi\Mutation\Compiler\Pass\MergeMutationInput;
use ON\RestApi\Mutation\Compiler\Pass\ParseDirectusPayload;
use ON\RestApi\Mutation\Compiler\Pass\ReconcileRelationChildren;
use ON\RestApi\Mutation\Compiler\Pass\ResolveMutationOperations;
use ON\RestApi\Mutation\Compiler\Pass\ValidateMutationPlan;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Mutation\RecordStore;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;

/**
 * Orchestrates the record-store hydration pipeline from raw input to a fully
 * hydrated record graph.
 */
final class RecordStoreCompiler
{
	/** @var list<HydrationPassInterface>|null */
	private ?array $nodePasses;

	/**
	 * @param list<HydrationPassInterface>|null $passes
	 */
	public function __construct(
		private readonly ItemRepositoryInterface $items,
		private readonly HandlerFactory $handlers,
		private readonly CycleRecordLoader $records,
		?array $passes = null,
	) {
		$this->nodePasses = $passes;
	}

	/**
	 * @return list<HydrationPassInterface>
	 */
	public function inputPasses(): array
	{
		return [
			new MergeMutationInput(),
			new ParseDirectusPayload(),
		];
	}

	public function compile(CollectionInterface $collection, array $input, HydrationOptions $options): RecordStore
	{
		$root = $this->runPasses(new HydrationInput($collection, $input, $options), $this->inputPasses());
		if (! $root instanceof RecordNode) {
			throw new \RuntimeException('Mutation compiler did not produce a record node.');
		}

		$this->hydrateNodeTree($root, $options, null);

		return new RecordStore($root);
	}

	/**
	 * @return list<HydrationPassInterface>
	 */
	public function defaultNodePasses(
		HydrationOptions $options,
	): array {
		return [
			new AttachRelationDefinitions($this->handlers),
			new ResolveMutationOperations($this->items, $this->records, $options),
			new AttachMutationState(),
			new HydrateCycleRecords($this->records),
			new ReconcileRelationChildren(),
			new ApplyCycleRecordGraph($this->records),
			new ValidateMutationPlan(),
		];
	}

	/**
	 * @param list<HydrationPassInterface> $passes
	 */
	private function runPasses(HydrationSubjectInterface $subject, array $passes): HydrationSubjectInterface
	{
		foreach ($passes as $pass) {
			$subject = $pass->run($subject);
		}

		return $subject;
	}

	private function hydrateNodeTree(
		RecordNode $node,
		HydrationOptions $options,
		?NodeStateInterface $parentState,
	): void {
		$passes = $this->nodePasses ?? $this->defaultNodePasses($options);

		$effectivePasses = [];
		foreach ($passes as $pass) {
			$effectivePasses[] = $pass instanceof AttachMutationState
				? new AttachMutationState($parentState)
				: $pass;
		}

		$subject = $this->runPasses($node, $effectivePasses);
		if (! $subject instanceof RecordNode) {
			throw new \RuntimeException('Mutation compiler did not produce a record node.');
		}

		foreach ($subject->relations as $relation) {
			foreach ($relation->children as $child) {
				if (! $child->isRelationMutation()) {
					continue;
				}

				$this->hydrateNodeTree(
					$child,
					$this->optionsForChildNode($child, $options),
					$subject->state,
				);
			}
		}

		foreach ($effectivePasses as $pass) {
			if ($pass instanceof ApplyCycleRecordGraph) {
				$pass->run($subject);
			}
		}
	}

	private function optionsForChildNode(
		RecordNode $child,
		HydrationOptions $parentOptions,
	): HydrationOptions {
		$mode = match ($child->plannedOperation) {
			'delete' => 'delete',
			'create' => 'create',
			default => 'upsert',
		};

		$id = null;
		if ($mode !== 'create') {
			$id = $child->collection->getPrimaryKey()->extract($child->fields);
		}

		return new HydrationOptions($mode, $id, $parentOptions->files);
	}
}
