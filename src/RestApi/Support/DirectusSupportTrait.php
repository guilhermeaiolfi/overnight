<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\RelationAggregateSelection;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\WildcardSelection;

trait DirectusSupportTrait
{
	protected function buildSelectionPlan(SelectionSet $selection): array
	{
		$fields = [];
		$requestedFields = [];
		$relations = [];

		foreach ($selection->nodes as $node) {
			if ($node instanceof WildcardSelection) {
				return ['fields' => [], 'requestedFields' => [], 'relations' => []];
			}

			if ($node instanceof FieldSelection) {
				$fields[] = $node->field->field;
				if (!$node->internal) {
					$requestedFields[] = $node->field->field;
				}
				continue;
			}

			if ($node instanceof RelationSelection) {
				$relations[] = $node;
				continue;
			}

			if ($node instanceof RelationAggregateSelection) {
				throw new RestApiError(
					"Relation aggregate '{$node->responseName}' is not supported by the SQL REST resolver yet.",
					'UNSUPPORTED_RELATION_AGGREGATE',
					$node->responseName,
					400
				);
			}
		}

		return [
			'fields' => array_values(array_unique($fields)),
			'requestedFields' => array_values(array_unique($requestedFields)),
			'relations' => $relations,
		];
	}

	protected function aggregateAlias(string $function, string $field): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $function . '_' . $field);
	}

	protected function formatAggregateRows(array $rows, array $aggregates, array $groupBy): array
	{
		$result = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ($groupBy as $group) {
				if (!$group instanceof \ON\RestApi\Query\Node\GroupBySpec) {
					continue;
				}

				$responseName = $group->responseName;
				$alias = $group->alias ?? $this->querySpecCompiler->alias($group->expression);
				if (array_key_exists($alias, $row)) {
					$entry[$responseName] = $row[$alias];
				}
			}

			foreach ($aggregates as $aggregate) {
				if (!$aggregate instanceof \ON\RestApi\Query\Node\AggregateSpec) {
					continue;
				}

				$alias = $this->aggregateAlias($aggregate->responseFunction, $aggregate->responseField);
				if (array_key_exists($alias, $row)) {
					$entry[$aggregate->responseFunction][$aggregate->responseField] = $row[$alias];
				}
			}

			$result[] = $entry;
		}

		return $result;
	}
}
