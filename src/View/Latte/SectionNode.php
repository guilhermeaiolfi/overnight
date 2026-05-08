<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;

class SectionNode extends StatementNode
{
	public ExpressionNode $name;

	public static function create(Tag $tag): static
	{
		$tag->outputMode = $tag::OutputRemoveIndentation;
		$tag->expectArguments('section name');

		$node = new static();
		$node->name = $tag->parser->parseUnquotedStringOrExpression();
		$node->position = $tag->position;

		return $node;
	}

	public function print(PrintContext $context): string
	{
		$id = $context->generateId();

		return $context->format(
			'$ʟ_section_' . $id . ' = %node;'
			. 'if (isset($__sections[$ʟ_section_' . $id . ']) && is_array($__sections[$ʟ_section_' . $id . '])) {'
			. 'if (($__sections[$ʟ_section_' . $id . '][\'type\'] ?? null) === \'text\') {'
			. 'echo $__sections[$ʟ_section_' . $id . '][\'content\'] ?? null;'
			. '} elseif (($__sections[$ʟ_section_' . $id . '][\'type\'] ?? null) === \'file\') {'
			. '$this->createTemplate($__sections[$ʟ_section_' . $id . '][\'content\'], get_defined_vars(), \'include\')->render();'
			. '}'
			. '} %line',
			$this->name,
			$this->position,
		);
	}

	public function &getIterator(): \Generator
	{
		yield $this->name;
	}
}
