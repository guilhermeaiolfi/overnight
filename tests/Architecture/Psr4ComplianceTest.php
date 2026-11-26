<?php

declare(strict_types=1);

namespace Tests\ON\Architecture;

use function class_exists;
use function count;
use const DIRECTORY_SEPARATOR;
use function dirname;
use function file_get_contents;
use FilesystemIterator;
use function implode;
use function in_array;
use function interface_exists;
use function is_array;
use function is_subclass_of;
use ON\Config\Scanner\Exception\FileNotFoundException;
use ON\Config\Scanner\Exception\UndefinedClassException;
use ON\RequestStackFrame;
use ON\Session\Native\NamespacedSession;
use ON\Session\SessionInterface;
use ON\Translation\Locale;
use ON\View\Plates\Exception\ExceptionInterface as PlatesExceptionInterface;
use ON\View\Plates\Exception\InvalidExtensionException;
use ON\View\Plates\Exception\MissingHelperException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use const T_CLASS;
use const T_COMMENT;
use const T_CONST;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_ENUM;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_WHITESPACE;
use function token_get_all;
use function trim;

/**
 * Guard: every PHP file in src/ must comply with case-sensitive PSR-4 autoloading for Linux/Docker.
 */
final class Psr4ComplianceTest extends TestCase
{
	public function testEverySourceFileMatchesPsr4NamespaceAndFilenameWithExactCase(): void
	{
		$srcRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src';
		$this->assertDirectoryExists($srcRoot);

		$violations = [];
		$scannedFiles = 0;

		foreach ($this->findPhpFiles($srcRoot) as $file) {
			$scannedFiles++;
			$path = $file->getPathname();
			$relative = str_replace('\\', '/', substr($path, strlen($srcRoot) + 1));
			$contents = file_get_contents($path);
			if ($contents === false) {
				$violations[] = sprintf('%s: failed to read file', $relative);

				continue;
			}

			$declarations = $this->extractTypeDeclarations($contents);

			if (count($declarations) > 1) {
				$names = [];
				foreach ($declarations as [$namespace, $symbol]) {
					$names[] = ($namespace !== '' ? $namespace . '\\' : '') . $symbol;
				}
				$violations[] = sprintf(
					'%s: declares multiple types (%s)',
					$relative,
					implode(', ', $names)
				);

				continue;
			}

			if (count($declarations) === 1) {
				[$namespace, $symbol] = $declarations[0];
				$expectedFqn = 'ON\\' . str_replace('/', '\\', substr($relative, 0, -4));
				$actualFqn = ($namespace !== '' ? $namespace . '\\' : '') . $symbol;

				if ($expectedFqn !== $actualFqn) {
					$violations[] = sprintf(
						'%s: expected %s, declared %s',
						$relative,
						$expectedFqn,
						$actualFqn
					);
				}
			}
		}

		$this->assertGreaterThan(0, $scannedFiles);
		$this->assertSame(
			[],
			$violations,
			"PSR-4 violations found under src/:\n" . implode("\n", $violations)
		);
	}

	public function testNoWrongCaseOnNamespaceImportsExist(): void
	{
		$srcRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src';
		$this->assertDirectoryExists($srcRoot);

		$violations = [];

		foreach ($this->findPhpFiles($srcRoot) as $file) {
			$path = $file->getPathname();
			$relative = str_replace('\\', '/', substr($path, strlen($srcRoot) + 1));
			$contents = file_get_contents($path);
			if ($contents === false) {
				continue;
			}

			foreach ($this->extractUseImports($contents) as $import) {
				if (
					strlen($import) >= 3
					&& str_starts_with(strtolower($import), 'on\\')
					&& ! str_starts_with($import, 'ON\\')
				) {
					$violations[] = sprintf('%s: wrong-case import "%s"', $relative, $import);
				}
			}
		}

		$this->assertSame(
			[],
			$violations,
			"Wrong-case ON\\ imports found under src/:\n" . implode("\n", $violations)
		);
	}

	public function testRepairedClassesAreAutoloadable(): void
	{
		$this->assertTrue(class_exists(FileNotFoundException::class));
		$this->assertTrue(class_exists(UndefinedClassException::class));
		$this->assertTrue(class_exists(RequestStackFrame::class));
		$this->assertTrue(class_exists(Locale::class));
		$this->assertTrue(interface_exists(PlatesExceptionInterface::class));
		$this->assertTrue(class_exists(InvalidExtensionException::class));
		$this->assertTrue(class_exists(MissingHelperException::class));
		$this->assertTrue(is_subclass_of(NamespacedSession::class, SessionInterface::class));
	}

	/**
	 * @return list<SplFileInfo>
	 */
	private function findPhpFiles(string $srcRoot): array
	{
		$iterator = new RegexIterator(
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
			),
			'/\.php$/i',
			RecursiveRegexIterator::MATCH
		);

		$files = [];
		/** @var SplFileInfo $file */
		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private function extractTypeDeclarations(string $code): array
	{
		$tokens = token_get_all($code);
		$count = count($tokens);
		$namespace = '';
		$declarations = [];

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (! is_array($token)) {
				continue;
			}

			if ($token[0] === T_NAMESPACE) {
				$name = '';
				$i++;
				while ($i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
					if (
						is_array($tokens[$i])
						&& in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)
					) {
						$name .= $tokens[$i][1];
					}
					$i++;
				}
				$namespace = trim($name, '\\');

				continue;
			}

			if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
				continue;
			}

			$previous = $this->findAdjacentSignificantToken($tokens, $i, -1);
			if (
				is_array($previous)
				&& in_array($previous[0], [
					T_DOUBLE_COLON,
					T_OBJECT_OPERATOR,
					T_NULLSAFE_OBJECT_OPERATOR,
					T_NEW,
					T_FUNCTION,
					T_CONST,
				], true)
			) {
				continue;
			}

			$next = $this->findAdjacentSignificantToken($tokens, $i, 1);
			if (is_array($next) && $next[0] === T_STRING) {
				$declarations[] = [$namespace, $next[1]];
			}
		}

		return $declarations;
	}

	/**
	 * @return list<string>
	 */
	private function extractUseImports(string $code): array
	{
		$tokens = token_get_all($code);
		$count = count($tokens);
		$imports = [];
		$braceDepth = 0;

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token === '{') {
				$braceDepth++;

				continue;
			}
			if ($token === '}') {
				$braceDepth = $braceDepth > 0 ? $braceDepth - 1 : 0;

				continue;
			}

			if (! is_array($token) || $token[0] !== T_USE || $braceDepth > 0) {
				continue;
			}

			$segment = '';
			$i++;
			while ($i < $count && $tokens[$i] !== ';') {
				if (is_array($tokens[$i])) {
					if (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
						$segment .= $tokens[$i][1];
					}
				} elseif ($tokens[$i] === ',') {
					if ($segment !== '') {
						$imports[] = ltrim($segment, '\\');
						$segment = '';
					}
				}
				$i++;
			}

			if ($segment !== '') {
				$imports[] = ltrim($segment, '\\');
			}
		}

		return $imports;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private function findAdjacentSignificantToken(array $tokens, int $index, int $step): array|string|null
	{
		$count = count($tokens);
		for ($j = $index + $step; $j >= 0 && $j < $count; $j += $step) {
			if (
				is_array($tokens[$j])
				&& in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
			) {
				continue;
			}

			return $tokens[$j];
		}

		return null;
	}
}
