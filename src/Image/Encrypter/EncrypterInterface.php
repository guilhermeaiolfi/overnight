<?php

declare(strict_types=1);

namespace ON\Image\Encrypter;

use ON\Image\ImageRequest;

interface EncrypterInterface
{
	public function decrypt(string $token): ?ImageRequest;

	public function encrypt(ImageRequest $data): ?string;
}
