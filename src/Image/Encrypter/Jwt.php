<?php

declare(strict_types=1);

namespace ON\Image\Encrypter;

use Exception;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use ON\Image\ImageRequest;

class Jwt implements EncrypterInterface
{
	public const DATA_CLAIM = 'im';
	protected Configuration $configuration;

	public function __construct(string $key)
	{

		$signatureKey = new Key($key);
		$signer = new Sha512();
		$this->configuration = Configuration::forSymmetricSigner(
			// You may use any HMAC variations (256, 384, and 512)
			$signer,
			// replace the value below with a key of your own!
			$signatureKey
			// You may also override the JOSE encoder/decoder if needed by providing extra arguments here
		);
	}

	public function decrypt(string $token): ?ImageRequest
	{

		try {

			$token = $this->configuration->getParser()->parse(str_replace('/', '', $token));

			$signer_constraint = new SignedWith($this->configuration->getSigner(), $this->configuration->getSigningKey());
			//$expiration_contraint = new \Lcobucci\JWT\Validation\Constraint\ValidAt($clock);

			if (! $this->configuration->getValidator()->validate($token, $signer_constraint)) {
				return null;
			}



			$payload = $token->claims()->get(self::DATA_CLAIM);

			if ($payload) {
				$payload["sourceFilePath"] = str_replace('//', '/', $payload["sourceFilePath"]);
			}

			return is_array($payload) ? ImageRequest::fromArray($payload) : null;
		} catch (Exception $exception) {
			return null;
		}
	}

	public function encrypt(ImageRequest $data): ?string
	{


		$token = $this->configuration->createBuilder()
			->permittedFor("image")
			->withClaim(self::DATA_CLAIM, $data->toArray())
			->getToken($this->configuration->getSigner(), $this->configuration->getSigningKey());

		return $token->toString();
	}
}
