<?php

declare(strict_types=1);

namespace ON\Image\Encrypter;

interface EncrypterInterface
{
	/**
	 * @return array<string, mixed>|null
	 */
	public function decrypt(string $token): ?array;

	public function encrypt(array $data): ?string;
}
