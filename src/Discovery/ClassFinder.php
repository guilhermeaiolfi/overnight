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
		$classes = [];
		$namespace = '';
		$tokens = PhpToken::tokenize($php_code);

		for ($i = 0; $i < count($tokens); $i++) {
			if ($tokens[$i]->getTokenName() === 'T_NAMESPACE') {
				for ($j = $i + 1; $j < count($tokens); $j++) {
					if ($tokens[$j]->getTokenName() === 'T_NAME_QUALIFIED') {
						$namespace = $tokens[$j]->text;

						break;
					}
				}
			}

			if ($tokens[$i]->getTokenName() === 'T_CLASS') {
				// Skip anonymous classes (preceded by 'new')
				$prevIndex = $i - 1;
				while ($prevIndex >= 0 && $tokens[$prevIndex]->getTokenName() === 'T_WHITESPACE') {
					$prevIndex--;
				}
				if ($prevIndex >= 0 && $tokens[$prevIndex]->getTokenName() === 'T_NEW') {
					continue;
				}

				for ($j = $i + 1; $j < count($tokens); $j++) {
					if ($tokens[$j]->getTokenName() === 'T_WHITESPACE') {
						continue;
					}

					if ($tokens[$j]->getTokenName() === 'T_STRING') {
						$classes[] = $namespace . '\\' . $tokens[$j]->text;
					} else {
						break;
					}
				}
			}
		}

		return $classes;
	}
}
