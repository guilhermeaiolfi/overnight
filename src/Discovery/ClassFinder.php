<?php

declare(strict_types=1);

namespace ON\Discovery;

use PhpToken;

class ClassFinder
{
	protected $cache = [];

	public function getClassesInFile($filepath)
	{
		if (isset($this->cache[$filepath])) {
			return $this->cache[$filepath];
		}
		$php_code = file_get_contents($filepath);
		$classes = $this->getClassesFromCode($php_code);
		$this->cache[$filepath] = $classes;

		return $classes;
	}

	public function getClassesFromCode($php_code)
	{
		return $this->getFromCode($php_code, [T_CLASS]);
	}

	public function getFromCode($php_code, array $tokensToFind)
	{
		$classes = [];
		$namespace = '';
		$namespaceStack = [];
		$braceDepth = 0;
		$tokens = PhpToken::tokenize($php_code);
		$tokenNamesToFind = $this->normalizeTokenNames($tokensToFind);

		for ($i = 0; $i < count($tokens); $i++) {
			if ($tokens[$i]->text === '}') {
				$braceDepth = max(0, $braceDepth - 1);
				while (! empty($namespaceStack) && end($namespaceStack)['depth'] > $braceDepth) {
					$namespace = array_pop($namespaceStack)['namespace'];
				}
			}

			if ($tokens[$i]->getTokenName() === 'T_NAMESPACE') {
				[$namespaceName, $opensBlock] = $this->parseNamespace($tokens, $i);
				if ($opensBlock) {
					$namespaceStack[] = [
						'namespace' => $namespace,
						'depth' => $braceDepth + 1,
					];
				}
				$namespace = $namespaceName;
			}

			if (in_array($tokens[$i]->getTokenName(), $tokenNamesToFind, true)) {
				if ($tokens[$i]->getTokenName() === 'T_CLASS') {
					$prevIndex = $this->previousSignificantTokenIndex($tokens, $i);
					if ($prevIndex !== null && $tokens[$prevIndex]->getTokenName() === 'T_NEW') {
						continue;
					}
				}

				$nameIndex = $this->nextSignificantTokenIndex($tokens, $i);
				if ($nameIndex !== null && $tokens[$nameIndex]->getTokenName() === 'T_STRING') {
					$classes[] = $this->qualifyName($namespace, $tokens[$nameIndex]->text);
				}
			}

			if ($tokens[$i]->text === '{') {
				$braceDepth++;
			}
		}

		return $classes;
	}

	private function normalizeTokenNames(array $tokensToFind): array
	{
		return array_map(function ($token) {
			return is_int($token) ? token_name($token) : $token;
		}, $tokensToFind);
	}

	private function parseNamespace(array $tokens, int $namespaceIndex): array
	{
		$namespace = '';

		for ($i = $namespaceIndex + 1; $i < count($tokens); $i++) {
			if ($this->isIgnorableToken($tokens[$i])) {
				continue;
			}

			if ($tokens[$i]->text === ';') {
				return [$this->normalizeNamespace($namespace), false];
			}

			if ($tokens[$i]->text === '{') {
				return [$this->normalizeNamespace($namespace), true];
			}

			$tokenName = $tokens[$i]->getTokenName();
			if (in_array($tokenName, ['T_STRING', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'], true)) {
				$namespace .= $tokens[$i]->text;

				continue;
			}

			if ($tokens[$i]->text === '\\') {
				$namespace .= '\\';
			}
		}

		return [$this->normalizeNamespace($namespace), false];
	}

	private function normalizeNamespace(string $namespace): string
	{
		return trim($namespace, '\\');
	}

	private function previousSignificantTokenIndex(array $tokens, int $index): ?int
	{
		for ($i = $index - 1; $i >= 0; $i--) {
			if (! $this->isIgnorableToken($tokens[$i])) {
				return $i;
			}
		}

		return null;
	}

	private function nextSignificantTokenIndex(array $tokens, int $index): ?int
	{
		for ($i = $index + 1; $i < count($tokens); $i++) {
			if (! $this->isIgnorableToken($tokens[$i])) {
				return $i;
			}
		}

		return null;
	}

	private function isIgnorableToken(PhpToken $token): bool
	{
		return in_array($token->getTokenName(), ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true);
	}

	private function qualifyName(string $namespace, string $name): string
	{
		if ($namespace === '') {
			return $name;
		}

		return $namespace . '\\' . $name;
	}
}
