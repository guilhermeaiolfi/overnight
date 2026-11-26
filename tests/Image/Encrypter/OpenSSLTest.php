<?php

declare(strict_types=1);

namespace Tests\ON\Image\Encrypter;

use InvalidArgumentException;
use ON\Image\Encrypter\OpenSSL;
use ON\Image\ImageRequest;
use PHPUnit\Framework\TestCase;

final class OpenSSLTest extends TestCase
{
	private const TEST_KEY = 'super-secret-signing-key-for-tests-32b';

	public function testEncryptAndDecryptRoundTrip(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);
		$request = new ImageRequest('uploads/photo.jpg', 'custom', 'cover:640,360');

		$token = $encrypter->encrypt($request);

		$this->assertNotNull($token);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);

		$decrypted = $encrypter->decrypt($token);

		$this->assertInstanceOf(ImageRequest::class, $decrypted);
		$this->assertSame('uploads/photo.jpg', $decrypted->getSourceFilePath());
		$this->assertSame('custom', $decrypted->getTemplate());
		$this->assertSame('cover:640,360', $decrypted->getOptions());
	}

	public function testEncryptHandlesNullOptionsAndSpecialCharacters(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);
		$request = new ImageRequest('folder/a&b?c#1.png', 'original', null);

		$token = $encrypter->encrypt($request);
		$this->assertNotNull($token);

		$decrypted = $encrypter->decrypt($token);
		$this->assertInstanceOf(ImageRequest::class, $decrypted);
		$this->assertSame('folder/a&b?c#1.png', $decrypted->getSourceFilePath());
		$this->assertSame('original', $decrypted->getTemplate());
		$this->assertNull($decrypted->getOptions());
	}

	public function testEncryptIsDeterministicForCacheStability(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);
		$request = new ImageRequest('photo.jpg', 'thumb', 'resize:200,200');

		$first = $encrypter->encrypt($request);
		$second = $encrypter->encrypt($request);

		$this->assertSame($first, $second);
	}

	public function testRejectsEmptyKey(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new OpenSSL('');
	}

	public function testRejectsWhitespaceOnlyKey(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new OpenSSL('   ');
	}

	public function testDecryptRejectsFlippedIvByte(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);
		$token = $encrypter->encrypt(new ImageRequest('photo.jpg', 'thumb'));
		$this->assertNotNull($token);

		$raw = $this->decodeUrlSafeBase64($token);
		// Flip bit in the first byte (IV)
		$raw[0] = chr(ord($raw[0]) ^ 0x01);
		$tamperedToken = $this->encodeUrlSafeBase64($raw);

		$this->assertNull($encrypter->decrypt($tamperedToken));
	}

	public function testDecryptRejectsTamperedHmacByte(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);
		$token = $encrypter->encrypt(new ImageRequest('photo.jpg', 'thumb'));
		$this->assertNotNull($token);

		$raw = $this->decodeUrlSafeBase64($token);
		// Flip bit in the HMAC region (bytes 16..47)
		$raw[20] = chr(ord($raw[20]) ^ 0x80);
		$tamperedToken = $this->encodeUrlSafeBase64($raw);

		$this->assertNull($encrypter->decrypt($tamperedToken));
	}

	public function testDecryptRejectsTamperedCiphertextByte(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);
		$token = $encrypter->encrypt(new ImageRequest('photo.jpg', 'thumb'));
		$this->assertNotNull($token);

		$raw = $this->decodeUrlSafeBase64($token);
		// Flip bit in the final byte (ciphertext / padding)
		$lastIndex = strlen($raw) - 1;
		$raw[$lastIndex] = chr(ord($raw[$lastIndex]) ^ 0x01);
		$tamperedToken = $this->encodeUrlSafeBase64($raw);

		$this->assertNull($encrypter->decrypt($tamperedToken));
	}

	public function testDecryptRejectsWrongKey(): void
	{
		$encrypterA = new OpenSSL('key-alpha-1234567890');
		$encrypterB = new OpenSSL('key-beta-0987654321');

		$token = $encrypterA->encrypt(new ImageRequest('photo.jpg', 'thumb'));
		$this->assertNotNull($token);

		$this->assertNull($encrypterB->decrypt($token));
	}

	public function testDecryptRejectsMalformedAndTruncatedTokens(): void
	{
		$encrypter = new OpenSSL(self::TEST_KEY);

		$this->assertNull($encrypter->decrypt(''));
		$this->assertNull($encrypter->decrypt('!!!not-base64!!!'));
		$this->assertNull($encrypter->decrypt($this->encodeUrlSafeBase64('too-short')));
	}

	private function decodeUrlSafeBase64(string $token): string
	{
		$normalized = strtr($token, '-_', '+/');
		$remainder = strlen($normalized) % 4;
		if ($remainder !== 0) {
			$normalized .= str_repeat('=', 4 - $remainder);
		}

		$decoded = base64_decode($normalized, true);
		$this->assertNotFalse($decoded);

		return $decoded;
	}

	private function encodeUrlSafeBase64(string $binary): string
	{
		return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
	}
}
