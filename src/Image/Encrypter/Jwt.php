<?php
namespace ON\Image\Encrypter;

use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

class Jwt implements EncrypterInterface {
    const DATA_CLAIM = 'im';
    protected $configuration = null;
    public function __construct($key) {

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
    public function decrypt($token) {

        try {

            $token = $this->configuration->getParser()->parse(str_replace('/', '', $token));

            $signer_constraint = new SignedWith($this->configuration->getSigner(), $this->configuration->getSigningKey());
            //$expiration_contraint = new \Lcobucci\JWT\Validation\Constraint\ValidAt($clock);

            if (!$this->configuration->getValidator()->validate($token, $signer_constraint)) {
                return null;
            }



            $payload = $token->claims()->get(self::DATA_CLAIM);

            if ($payload) {
                $payload["path"] = str_replace('//', '/', $payload["path"]);
            }

            return $payload;
        } catch (\Exception $exception) {
            return null;
        }
    }

    public function encrypt($data) {


        $token = $this->configuration->createBuilder()
            ->permittedFor("image")
            ->withClaim(self::DATA_CLAIM, $data)
            ->getToken($this->configuration->getSigner(), $this->configuration->getSigningKey());

        return $token->toString();
    }
}