<?php

declare(strict_types=1);

namespace Tests\ON\Discovery;

use ON\Discovery\ClassFinder;
use PHPUnit\Framework\TestCase;

final class ClassFinderTest extends TestCase
{
	private ClassFinder $finder;

	protected function setUp(): void
	{
		$this->finder = new ClassFinder();
	}

	public function testFindsClassInSingleSegmentNamespace(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

namespace App;

class Foo {}
PHP);

		$this->assertSame(['App\\Foo'], $classes);
	}

	public function testFindsClassInQualifiedNamespace(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

namespace App\Modules;

class Foo {}
PHP);

		$this->assertSame(['App\\Modules\\Foo'], $classes);
	}

	public function testFindsClassesAcrossBracketedNamespaces(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

namespace App {
	class Foo {}
}

namespace {
	class Bar {}
}

namespace Other {
	class Baz {}
}
PHP);

		$this->assertSame(['App\\Foo', 'Bar', 'Other\\Baz'], $classes);
	}

	public function testSkipsAnonymousClassesWithComments(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

namespace App;

$object = new /* ignored */ class {};

class Foo {}
PHP);

		$this->assertSame(['App\\Foo'], $classes);
	}

	public function testFindsNamedClassWithCommentBeforeName(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

namespace App;

class /* comment */ Foo {}
PHP);

		$this->assertSame(['App\\Foo'], $classes);
	}

	public function testFindsGlobalClassWithoutLeadingNamespaceSeparator(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

class Foo {}
PHP);

		$this->assertSame(['Foo'], $classes);
	}

	public function testGetFromCodeFindsRequestedNamedSymbols(): void
	{
		$symbols = $this->finder->getFromCode(<<<'PHP'
<?php

namespace App;

interface Contract {}
trait SharedBehavior {}
enum Status {}
class Service {}
PHP, [T_INTERFACE, T_TRAIT, T_ENUM, T_CLASS]);

		$this->assertSame([
			'App\\Contract',
			'App\\SharedBehavior',
			'App\\Status',
			'App\\Service',
		], $symbols);
	}

	public function testClassConstantReferenceIsIgnored(): void
	{
		$classes = $this->finder->getClassesFromCode(<<<'PHP'
<?php

namespace App;

$name = Foo::class;

class Bar {}
PHP);

		$this->assertSame(['App\\Bar'], $classes);
	}
}
