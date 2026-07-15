<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use DI\ContainerBuilder;
use ON\Data\Definition\Registry;
use ON\RestApi\Action\Directus\BatchUpdateAction;
use ON\RestApi\Action\Directus\CreateAction;
use ON\RestApi\Action\Directus\UpdateAction;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * PHP-DI does not inject optional constructor params (`Type $x = null`).
 * FileUploadEventEmitter must stay required so multipart uploads become stored ids.
 */
final class FileUploadEventEmitterInjectionTest extends TestCase
{
	public function testMutationActionsRequireFileUploadEventEmitterWithoutDefaultNull(): void
	{
		foreach ([CreateAction::class, UpdateAction::class, BatchUpdateAction::class] as $actionClass) {
			$parameter = $this->fileUploadParameter($actionClass);

			$this->assertFalse(
				$parameter->isDefaultValueAvailable(),
				$actionClass . '::$fileUploadEventEmitter must not default to null (PHP-DI would skip injection).',
			);
			$this->assertSame(FileUploadEventEmitter::class, $parameter->getType()?->getName());
			$this->assertFalse($parameter->getType()?->allowsNull() ?? true);
		}
	}

	public function testPhpDiAutowiresFileUploadEventEmitterIntoUpdateAction(): void
	{
		$emitter = (new ReflectionClass(FileUploadEventEmitter::class))->newInstanceWithoutConstructor();
		$items = $this->createStub(ItemRepositoryInterface::class);
		$mutations = (new ReflectionClass(MutationCoordinator::class))->newInstanceWithoutConstructor();

		$builder = new ContainerBuilder();
		$builder->useAutowiring(true);
		$builder->addDefinitions([
			Registry::class => static fn (): Registry => new Registry(),
			RestApiConfig::class => static fn (): RestApiConfig => new RestApiConfig([]),
			ItemRepositoryInterface::class => static fn () => $items,
			MutationCoordinator::class => static fn () => $mutations,
			FileUploadEventEmitter::class => static fn (): FileUploadEventEmitter => $emitter,
		]);

		$update = $builder->build()->get(UpdateAction::class);
		$prop = (new ReflectionClass(UpdateAction::class))->getProperty('fileUploadEventEmitter');

		$this->assertSame($emitter, $prop->getValue($update));
	}

	/**
	 * @param class-string $actionClass
	 */
	private function fileUploadParameter(string $actionClass): ReflectionParameter
	{
		$constructor = (new ReflectionClass($actionClass))->getConstructor();
		$this->assertNotNull($constructor);

		foreach ($constructor->getParameters() as $parameter) {
			if ($parameter->getName() === 'fileUploadEventEmitter') {
				return $parameter;
			}
		}

		$this->fail($actionClass . ' is missing fileUploadEventEmitter constructor parameter.');
	}
}
