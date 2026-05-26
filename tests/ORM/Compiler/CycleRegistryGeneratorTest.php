<?php

declare(strict_types=1);

namespace Tests\ON\ORM\Compiler;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\Schema\Registry as CycleRegistry;
use ON\Mapper\Field\Handler\DateTimeFieldType;
use ON\Mapper\Field\Handler\StringFieldType;
use ON\ORM\Compiler\CycleRegistryGenerator;
use ON\ORM\Definition\Exception\FieldException;
use ON\ORM\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class CycleRegistryGeneratorTest extends TestCase
{
	public function testStringFieldUsesMaxLengthInCycleType(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->table('user')
			->field('name', 'string')->type('string')->maxLength(64)->end()
			->end();

		$cycleField = $this->convertField($registry, 'user', 'name');

		$this->assertSame('string(64)', $cycleField->getType());
	}

	public function testStringFieldDefaultsToMaxLength255(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->table('user')
			->field('email', 'string')->type('string')->end()
			->end();

		$cycleField = $this->convertField($registry, 'user', 'email');

		$this->assertSame('string(255)', $cycleField->getType());
	}

	public function testNonStringFieldTypeIsUnchanged(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->table('post')
			->field('content', 'text')->type('text')->maxLength(255)->end()
			->end();

		$cycleField = $this->convertField($registry, 'post', 'content');

		$this->assertSame('text', $cycleField->getType());
	}

	public function testExplicitCycleStringLengthIsPreserved(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->table('user')
			->field('code', 'string')->type('string(32)')->maxLength(255)->end()
			->end();

		$cycleField = $this->convertField($registry, 'user', 'code');

		$this->assertSame('string(32)', $cycleField->getType());
	}

	public function testFieldTypeHandlerUsesStorageType(): void
	{
		$registry = new Registry();
		$registry->collection('event')
			->table('event')
			->field('starts_at', 'datetime')->type(DateTimeFieldType::class)->end()
			->end();

		$cycleField = $this->convertField($registry, 'event', 'starts_at');

		$this->assertSame('datetime', $cycleField->getType());
	}

	public function testFieldTypeHandlerStringUsesMaxLength(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->table('user')
			->field('name', 'string')->type(StringFieldType::class)->maxLength(128)->end()
			->end();

		$cycleField = $this->convertField($registry, 'user', 'name');

		$this->assertSame('string(128)', $cycleField->getType());
	}

	public function testUnknownFieldTypeThrows(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->table('user')
			->field('status', 'string')->type('status_enum')->end()
			->end();

		$this->expectException(FieldException::class);
		$this->expectExceptionMessage('Field(status) type "status_enum" is not a known Cycle column type');

		$this->convertField($registry, 'user', 'status');
	}

	public function testUnknownClassFieldTypeThrows(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->table('user')
			->field('status', 'string')->type(\stdClass::class)->end()
			->end();

		$this->expectException(FieldException::class);
		$this->expectExceptionMessage('Field(status) type "' . \stdClass::class . '" is not a known Cycle column type');

		$this->convertField($registry, 'user', 'status');
	}

	private function convertField(Registry $registry, string $collectionName, string $fieldName): \Cycle\Schema\Definition\Field
	{
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig()
				),
			],
		]));

		$cycleRegistry = new CycleRegistry($manager);
		(new CycleRegistryGenerator($registry))->run($cycleRegistry);

		$entity = $cycleRegistry->getEntity($collectionName);

		return $entity->getFields()->get($fieldName);
	}
}
