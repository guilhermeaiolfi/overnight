<?php

declare(strict_types=1);

namespace ON\Image\Encrypter;

class OpenSSL implements EncrypterInterface
{
	protected ?array $configuration = null;
	protected string $key;
	protected string $cipher = "AES-128-CBC";
	protected ?array $options = null;
	protected string $iv;

	public function __construct(string $key, ?array $options = null)
	{
		$this->key = $key;
		$this->options = $options;
		$this->iv = $options['iv'] ?? $_ENV["APP_SALT"] ?? substr(md5((string) $key), 0, 16);
	}

	public function decrypt(string $token): ?array
	{

		$token = str_replace(['-','_'], ['+','/'], $token);
		$token = base64_decode($token, true);
		if ($token === false) {
			return null;
		}
		$ivlen = openssl_cipher_iv_length($this->cipher);
		$iv = substr($token, 0, $ivlen);
		//$iv=1234567890123456;
		//$hmac = substr($token, $ivlen, $sha2len=32);
		//$raw = substr($token, $ivlen+$sha2len);
		$raw = substr($token, $ivlen);

		$data = openssl_decrypt($raw, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
		if ($data === false) {
			return null;
		}

		//$calcmac = hash_hmac('sha256', $raw, $this->key, $as_binary=true);
		$params = parse_url($data);
		if ($params === false || ! isset($params["path"], $params["query"])) {
			return null;
		}
		parse_str($params["query"], $data);

		return [
			"path" => $params["path"],
			"template" => $data["t"] ?? null,
			"options" => $data["o"] ?? null,
		];
	}

	public function encrypt(array $data): ?string
	{
		$plaintext = $data["path"] . "?t=" . $data["template"] . '&o=' . $data["options"];
		$ivlen = openssl_cipher_iv_length($this->cipher);

		// TODO: figured it out what iv should be
		// it was: $iv = substr(md5(filemtime('public/' . $data["path"])), 0, $ivlen);
		$iv = substr(md5($data["path"]), 0, $ivlen);
		//$iv=1234567890123456;
		//$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
		$ciphertext_raw = openssl_encrypt($plaintext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
		$hmac = ""; // hash_hmac('sha256', $ciphertext_raw, "123", $as_binary=true);
		$token = base64_encode($iv.$hmac.$ciphertext_raw);

		return str_replace(['+','/','='], ['-','_',''], $token);
	}
}
