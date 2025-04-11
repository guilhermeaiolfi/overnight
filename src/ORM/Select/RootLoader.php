<?php

declare(strict_types=1);

namespace ON\ORM\Select;

use Cycle\Database\Query\SelectQuery;
use Cycle\Database\StatementInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\RootNode;
use function is_array;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Registry;
use ON\ORM\FactoryInterface;
use ON\ORM\Select\Traits\ColumnsTrait;
use ON\ORM\Select\Traits\ScopeTrait;

/**
 * Primary ORM loader. Loader wraps at top of select query in order to modify it's conditions, joins
 * and etc based on nested loaders.
 *
 * Root load does not load constrain from ORM by default.
 *
 * @method RootNode createNode()
 *
 * @internal
 */
final class RootLoader extends AbstractLoader
{
	use ColumnsTrait;
	use ScopeTrait;

	/** @var array */
	protected array $options = [
		'load' => true,
		'loadRelations' => true,
		'scope' => true,
		'columns' => ['*'],
	];

	private SelectQuery $query;

	/**
	 * @param bool $loadRelations Define loading eager relations and JTI hierarchy.
	 */
	public function __construct(
		Registry $registry,
		FactoryInterface $factory,
		Collection $target,
		array $options = []
	) {
		parent::__construct($registry, $factory, $target, $options);
		$this->query = $this->source->getDatabase()->select()->from(
			sprintf('%s AS %s', $this->source->getTable(), $this->getAlias())
		);
		$this->columns = $options["columns"] ?? $this->normalizeColumns((array) $target->fields->getColumnNames());

		if ($this->options["loadRelations"]) {
			foreach ($this->getEagerLoaders() as $relation) {
				$this->loadRelation($relation, [], false, true);
			}
		}
	}

	/**
	 * Clone the underlying query.
	 */
	public function __clone()
	{
		$this->query = clone $this->query;
		parent::__clone();
	}

	public function getAlias(): string
	{
		return $this->target->getName();
	}

	/**
	 * Primary column name list with table name like `table.column`.
	 *
	 * @return string|string[]
	 */
	public function getPK(): array|string
	{
		$pk = $this->target->getPrimaryKey();
		if (is_array($pk)) {
			$result = [];
			foreach ($pk as $field) {
				$result[] = $this->target . '.' . $field->getAlias();
			}

			return $result;
		}

		return $this->target . '.' . $pk->getAlias();
	}

	/**
	 * Get list of primary fields.
	 *
	 * @return list<non-empty-string>
	 */
	public function getPrimaryFields(): array
	{
		return $this->target->getPrimaryKey(true);
	}

	/**
	 * Return base query associated with the loader.
	 */
	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	/**
	 * Compile query with all needed conditions, columns and etc.
	 */
	public function buildQuery(): SelectQuery
	{
		return $this->configureQuery(clone $this->query);
	}

	public function loadData(AbstractNode $node, bool $includeRole = false): void
	{
		$statement = $this->buildQuery()->run();

		foreach ($statement->fetchAll(StatementInterface::FETCH_NUM) as $row) {
			$node->parseRow(0, $row);
		}

		$statement->close();

		// loading child datasets
		foreach ($this->load as $relation => $loader) {
			$loader->loadData($node->getNode($relation), $includeRole);
		}

		$this->loadHierarchy($node, $includeRole);
	}

	public function isLoaded(): bool
	{
		// root loader is always loaded
		return true;
	}

	protected function configureQuery(SelectQuery $query): SelectQuery
	{
		return parent::configureQuery(
			$this->mountColumns($query, true, '', true)
		);
	}

	protected function initNode(): RootNode
	{
		return new RootNode($this->columnNames(), (array)$this->target->getPrimaryKey(true));
	}
}
