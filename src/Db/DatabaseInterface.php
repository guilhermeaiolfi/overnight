<?php

declare(strict_types=1);

namespace ON\DB;

use Psr\Container\ContainerInterface;

interface DatabaseInterface
{
	public function __construct(string $name, DatabaseConfig $config, ContainerInterface $container);

	public function getConnection();

	public function getResource();

	public function setName(string $name): void;

	public function getName(): string;
}
