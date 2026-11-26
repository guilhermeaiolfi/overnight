<?php

declare(strict_types=1);

namespace ON\Image\Encrypter;

use InvalidArgumentException;
use JsonException;
use ON\Image\ImageRequest;

class OpenSSL implements EncrypterInterface
{
	private const MAC_LENGTH = 32;

	protected ?array $configuration = null;
	protected string $key;
	protected string $cipher = 'AES-256-CBC';
	protected ?array $options = null;

	private string $encryptionKey;
	private string $macKey;
	private string $ivKey;

	public function __construct(string $key, ?array $options = null)
	{
		if (trim($key) === '') {
			throw new InvalidArgumentException('OpenSSL encrypter requires a non-empty signing key.');
		}

		$this->key = $key;
		$this->options = $options;
		$this->encryptionKey = hash_hmac('sha256', 'overnight:image:enc:v1', $key, true);
		$this->macKey = hash_hmac('sha256', 'overnight:image:mac:v1', $key, true);
		$this->ivKey = hash_hmac('sha256', 'overnight:image:iv:v1', $key, true);
	}

	public function decrypt(string $token): ?ImageRequest
	{
		if ($token === '') {
			return null;
		}

		$normalized = strtr($token, '-_', '+/');
		$remainder = strlen($normalized) % 4;
		if ($remainder !== 0) {
			$normalized .= str_repeat('=', 4 - $remainder);
		}

		$decoded = base64_decode($normalized, true);
		if ($decoded === false) {
			return null;
		}

		$ivlen = openssl_cipher_iv_length($this->cipher);
		if ($ivlen === false || strlen($decoded) <= $ivlen + self::MAC_LENGTH) {
			return null;
		}

		$iv = substr($decoded, 0, $ivlen);
		$hmac = substr($decoded, $ivlen, self::MAC_LENGTH);
		$ciphertext = substr($decoded, $ivlen + self::MAC_LENGTH);

		$expectedMac = hash_hmac('sha256', $iv . $ciphertext, $this->macKey, true);
		if (! hash_equals($expectedMac, $hmac)) {
			return null;
		}

		$plaintext = openssl_decrypt($ciphertext, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
		if ($plaintext === false) {
			return null;
		}

		$payload = json_decode($plaintext, true);
		if (! is_array($payload)) {
			return null;
		}

		return ImageRequest::fromArray($payload);
	}

	public function encrypt(ImageRequest $data): ?string
	{
		try {
			$plaintext = json_encode([
				'path' => $data->getSourceFilePath(),
				'template' => $data->getTemplate(),
				'options' => $data->getOptions(),
			], JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		$ivlen = openssl_cipher_iv_length($this->cipher);
		if ($ivlen === false) {
			return null;
		}

		$iv = substr(hash_hmac('sha256', $plaintext, $this->ivKey, true), 0, $ivlen);
		$ciphertext = openssl_encrypt($plaintext, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
		if ($ciphertext === false) {
			return null;
		}

		$hmac = hash_hmac('sha256', $iv . $ciphertext, $this->macKey, true);
		$token = base64_encode($iv . $hmac . $ciphertext);

		return rtrim(strtr($token, '+/', '-_'), '=');
	}
}
