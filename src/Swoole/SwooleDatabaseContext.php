<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\DB\DatabaseContextInterface;
use ON\DB\DatabaseScope;
use Swoole\Coroutine;

final class SwooleDatabaseContext implements DatabaseContextInterface
{
	public function current(): ?DatabaseScope
	{
		if (! class_exists(Coroutine::class, false) || Coroutine::getCid() <= 0) {
			return null;
		}

		$context = Coroutine::getContext();
		if ($context === null) {
			return null;
		}

		if (! isset($context[self::class])) {
			$scope = $context[self::class] = new DatabaseScope();
			Coroutine::defer(static function () use ($context, $scope): void {
				unset($context[self::class]);
				$scope->close();
			});
		}

		return $context[self::class];
	}
}
