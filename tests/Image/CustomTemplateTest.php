<?php

declare(strict_types=1);

namespace Tests\ON\Image;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;
use InvalidArgumentException;
use ON\Image\CustomTemplate;
use PHPUnit\Framework\TestCase;

if (! interface_exists(ModifierInterface::class) || ! interface_exists(ImageInterface::class)) {
	require_once __DIR__ . '/Fixtures/InterventionStubs.php';
}

final class CustomTemplateTest extends TestCase
{
	public function testApplyExecutesAllowlistedMethods(): void
	{
		$image = new RecordingImageStub();
		$template = new CustomTemplate('cover:640,360,center|greyscale|blur:5');

		$result = $template->apply($image);

		$this->assertSame($image, $result);
		$this->assertSame([
			['cover', ['width' => 640, 'height' => 360, 'position' => 'center']],
			['greyscale', []],
			['blur', ['amount' => 5]],
		], $image->calls);
	}

	public function testApplySupportsNullableSingleDimension(): void
	{
		$image = new RecordingImageStub();
		$template = new CustomTemplate('scale:null,480');

		$template->apply($image);

		$this->assertSame([
			['scale', ['height' => 480]],
		], $image->calls);
	}

	public function testApplyRejectsDisallowedSaveMethod(): void
	{
		$image = new RecordingImageStub();
		$template = new CustomTemplate('save:/tmp/pwned.php');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not allowed');

		$template->apply($image);
	}

	public function testApplyRejectsOtherDangerousMethods(): void
	{
		foreach (['place:/etc/passwd', 'text:hello', 'encode:jpg', 'toPng:', 'modify:foo'] as $payload) {
			$image = new RecordingImageStub();
			$template = new CustomTemplate($payload);

			try {
				$template->apply($image);
				$this->fail(sprintf('Expected InvalidArgumentException for payload "%s".', $payload));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('not allowed', $e->getMessage());
				$this->assertSame([], $image->calls);
			}
		}
	}

	public function testApplyRejectsUnknownConstraints(): void
	{
		$image = new RecordingImageStub();
		$template = new CustomTemplate('resize:200,200/evil');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('constraint');

		$template->apply($image);
	}

	public function testApplyRejectsExcessiveDimensions(): void
	{
		$image = new RecordingImageStub();
		$template = new CustomTemplate('resize:5000,200');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid width');

		$template->apply($image);
	}

	public function testApplyRejectsExcessiveHeightWithCustomBounds(): void
	{
		$image = new RecordingImageStub();
		$template = new CustomTemplate('cover:800,1200', maxWidth: 1000, maxHeight: 1000);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid height');

		$template->apply($image);
	}

	public function testApplyRejectsNonPositiveAndInvalidDimensions(): void
	{
		foreach (['resize:0,100', 'resize:-50,100', 'resize:100,0', 'resize:abc,100', 'resize:99999999999999999999999,100'] as $payload) {
			$image = new RecordingImageStub();
			$template = new CustomTemplate($payload);

			try {
				$template->apply($image);
				$this->fail(sprintf('Expected InvalidArgumentException for payload "%s".', $payload));
			} catch (InvalidArgumentException) {
				$this->assertSame([], $image->calls);
			}
		}
	}

	public function testConstructorRejectsNonPositiveMaxDimensions(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new CustomTemplate('resize:100,100', maxWidth: 0, maxHeight: 100);
	}
}

final class RecordingImageStub implements ImageInterface
{
	/**
	 * @var list<array{0: string, 1: array<string|int, mixed>}>
	 */
	public array $calls = [];

	public function __call(string $name, array $arguments): self
	{
		$this->calls[] = [$name, $arguments];

		return $this;
	}
}
