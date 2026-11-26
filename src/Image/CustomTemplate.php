<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;
use InvalidArgumentException;

/*
* e.g. 'resize:500,100/ar/up'
*/
class CustomTemplate implements ModifierInterface
{
	public const DEFAULT_MAX_DIMENSION = 4096;

	protected array $contrainsMatrix = [
		'up' => 'upsize',
		'ar' => 'aspectRatio', // keep the aspect ratio, resizes proportionally
	];

	/**
	 * Explicit allowlist of safe Intervention Image manipulation methods and their named argument signatures.
	 *
	 * @var array<string, list<string>>
	 */
	protected array $modifiersArgsSignature = [
		'cover' => ['width', 'height', 'position'],
		'coverDown' => ['width', 'height', 'position'],
		'resize' => ['width', 'height'],
		'resizeDown' => ['width', 'height'],
		'scale' => ['width', 'height'],
		'scaleDown' => ['width', 'height'],
		'contain' => ['width', 'height', 'background', 'position'],
		'pad' => ['width', 'height', 'background', 'position'],
		'crop' => ['width', 'height', 'offset_x', 'offset_y', 'background', 'position'],
		'resizeCanvas' => ['width', 'height', 'background', 'position'],
		'rotate' => ['angle', 'background'],
		'flip' => [],
		'flop' => [],
		'greyscale' => [],
		'invert' => [],
		'orient' => [],
		'blur' => ['amount'],
		'sharpen' => ['amount'],
		'brightness' => ['level'],
		'contrast' => ['level'],
		'gamma' => ['gamma'],
		'pixelate' => ['size'],
		'colorize' => ['red', 'green', 'blue'],
	];

	protected ?string $options = null;
	protected int $maxWidth;
	protected int $maxHeight;

	public function __construct(
		?string $options,
		int $maxWidth = self::DEFAULT_MAX_DIMENSION,
		int $maxHeight = self::DEFAULT_MAX_DIMENSION
	) {
		if ($maxWidth < 1 || $maxHeight < 1) {
			throw new InvalidArgumentException('Maximum image dimensions must be positive integers.');
		}

		$this->options = $options;
		$this->maxWidth = $maxWidth;
		$this->maxHeight = $maxHeight;
	}

	public function parseArgs(array $args, ?callable $callback): array
	{
		$has_constraints = false;
		$parsed = [];

		foreach ($args as $arg) {
			$trimmed = trim((string) $arg);
			if ($trimmed === '') {
				$parsed[] = null;

				continue;
			}

			if ($trimmed === '$constraints') {
				$parsed[] = $callback;
				$has_constraints = true;
			} elseif ($trimmed === 'null') {
				$parsed[] = null;
			} elseif (filter_var($trimmed, FILTER_VALIDATE_INT) !== false) {
				$parsed[] = (int) $trimmed;
			} elseif (is_numeric($trimmed)) {
				$parsed[] = (float) $trimmed;
			} else {
				$parsed[] = $trimmed;
			}
		}

		if (! $has_constraints && $callback !== null) {
			$parsed[] = $callback;
		}

		return $parsed;
	}

	public function apply(ImageInterface $image): ImageInterface
	{
		$options = $this->options ?? '';
		if ($options === '') {
			return $image;
		}

		$commands = explode('|', $options);
		foreach ($commands as $command) {
			$command = trim($command);
			if ($command === '') {
				continue;
			}

			$parts = explode(':', $command, 2);
			$method = trim($parts[0]);
			$rawArgs = $parts[1] ?? '';

			if (! array_key_exists($method, $this->modifiersArgsSignature)) {
				throw new InvalidArgumentException(sprintf('Image manipulation method "%s" is not allowed.', $method));
			}

			$constraints = $rawArgs === '' ? [] : explode('/', $rawArgs);
			$rawMethodArgs = array_shift($constraints) ?? '';
			$argTokens = $rawMethodArgs === '' ? [] : explode(',', $rawMethodArgs);

			$func = null;
			if ($constraints !== []) {
				foreach ($constraints as $c) {
					if (! isset($this->contrainsMatrix[$c])) {
						throw new InvalidArgumentException(sprintf('Image constraint "%s" is not allowed.', $c));
					}
				}

				$contrainsMatrix = $this->contrainsMatrix;
				$func = static function ($constraintImage) use ($constraints, $contrainsMatrix): void {
					foreach ($constraints as $c) {
						$constraintImage->{$contrainsMatrix[$c]}();
					}
				};
			}

			$parsedArgs = $this->parseArgs($argTokens, $func);
			$namedArgs = $this->convertArgsToNamedArgs($method, $parsedArgs);
			$this->validateDimensions($method, $namedArgs);

			$image = $this->dispatchMethod($image, $method, $namedArgs);
		}

		return $image;
	}

	public function convertArgsToNamedArgs(string $method, array $args): array
	{
		if (! array_key_exists($method, $this->modifiersArgsSignature)) {
			throw new InvalidArgumentException(sprintf('Image manipulation method "%s" is not allowed.', $method));
		}

		$signature = $this->modifiersArgsSignature[$method];
		$namedArgs = [];
		foreach ($signature as $index => $name) {
			if (array_key_exists($index, $args) && $args[$index] !== null && ! is_callable($args[$index])) {
				$namedArgs[$name] = $args[$index];
			}
		}

		return $namedArgs;
	}

	/**
	 * @param array<string, mixed> $namedArgs
	 */
	protected function validateDimensions(string $method, array $namedArgs): void
	{
		if (array_key_exists('width', $namedArgs)) {
			$width = $namedArgs['width'];
			if (! is_int($width) || $width < 1 || $width > $this->maxWidth) {
				throw new InvalidArgumentException(sprintf(
					'Invalid width for "%s": must be an integer between 1 and %d.',
					$method,
					$this->maxWidth
				));
			}
		}

		if (array_key_exists('height', $namedArgs)) {
			$height = $namedArgs['height'];
			if (! is_int($height) || $height < 1 || $height > $this->maxHeight) {
				throw new InvalidArgumentException(sprintf(
					'Invalid height for "%s": must be an integer between 1 and %d.',
					$method,
					$this->maxHeight
				));
			}
		}
	}

	/**
	 * @param array<string, mixed> $namedArgs
	 */
	protected function dispatchMethod(ImageInterface $image, string $method, array $namedArgs): ImageInterface
	{
		return match ($method) {
			'cover' => $image->cover(...$namedArgs),
			'coverDown' => $image->coverDown(...$namedArgs),
			'resize' => $image->resize(...$namedArgs),
			'resizeDown' => $image->resizeDown(...$namedArgs),
			'scale' => $image->scale(...$namedArgs),
			'scaleDown' => $image->scaleDown(...$namedArgs),
			'contain' => $image->contain(...$namedArgs),
			'pad' => $image->pad(...$namedArgs),
			'crop' => $image->crop(...$namedArgs),
			'resizeCanvas' => $image->resizeCanvas(...$namedArgs),
			'rotate' => $image->rotate(...$namedArgs),
			'flip' => $image->flip(),
			'flop' => $image->flop(),
			'greyscale' => $image->greyscale(),
			'invert' => $image->invert(),
			'orient' => $image->orient(),
			'blur' => $image->blur(...$namedArgs),
			'sharpen' => $image->sharpen(...$namedArgs),
			'brightness' => $image->brightness(...$namedArgs),
			'contrast' => $image->contrast(...$namedArgs),
			'gamma' => $image->gamma(...$namedArgs),
			'pixelate' => $image->pixelate(...$namedArgs),
			'colorize' => $image->colorize(...$namedArgs),
			default => throw new InvalidArgumentException(sprintf('Image manipulation method "%s" is not allowed.', $method)),
		};
	}
}
