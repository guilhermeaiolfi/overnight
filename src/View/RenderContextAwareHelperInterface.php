<?php

declare(strict_types=1);

namespace ON\View;

interface RenderContextAwareHelperInterface
{
	public static function createFromRenderContext(RenderContext $context): mixed;
}
