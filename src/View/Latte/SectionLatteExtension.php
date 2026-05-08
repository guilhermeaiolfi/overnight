<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Extension;

class SectionLatteExtension extends Extension
{
	public function getTags(): array
	{
		return [
			'section' => fn(Tag $tag, TemplateParser $parser) => SectionNode::create($tag),
		];
	}
}
