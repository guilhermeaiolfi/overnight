<?php

declare(strict_types=1);

use ON\ORM\Select;
use PHPUnit\Framework\TestCase;
use Tests\ON\Fixtures\DummyLoader;
use Tests\ON\Fixtures\UserPartsRegistry;

final class SelectTest extends TestCase
{
	private $registry;
	private $dummyLoader;
	private $select;

	protected function setUp(): void
	{
		$this->registry = new UserPartsRegistry();
		$this->dummyLoader = new DummyLoader($this->registry);
		$this->select = new Select();
	}

	public function testSimpleFields(): void
	{
		$this->select->load([
			"parts" => [
				"columns" => [
					"id" => true,
				],
			],
			"parts.user" => [
				"columns" => [
					"id" => true,
					"name" => true,
				],
			],
		]);
	}
}
