<?php
namespace ON\Image\Encrypter;

class OpenSSL implements EncrypterInterface {
    protected $configuration = null;
    protected $key = null;
    protected $cipher = "AES-128-CBC";
    protected $options = null;
    protected $iv = null;
    public function __construct($key, $options = null) {
        $this->key = $key;
        $this->options = $options;
        $this->iv = isset($options['iv'])? $options['iv'] : $_ENV["APP_SALT"];
    }
    public function decrypt($token) {

        $token = str_replace(['-','_'], ['+','/'], $token);
        $token = base64_decode($token);
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = substr($token, 0, $ivlen);
        //$iv=1234567890123456;
        //$hmac = substr($token, $ivlen, $sha2len=32);
        //$raw = substr($token, $ivlen+$sha2len);
        $raw = substr($token, $ivlen);

        $data = openssl_decrypt($raw, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        //$calcmac = hash_hmac('sha256', $raw, $this->key, $as_binary=true);
        $params = parse_url($data);
        parse_str($params["query"], $data);

        return [
            "path" => $params["path"],
            "template" => $data["t"],
            "options" => $data["o"]
        ];
    }

    public function encrypt($data) {
        $plaintext = $data["path"] . "?t=" . $data["template"] . '&o=' . $data["options"];
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = substr(md5(filemtime('public/' . $data["path"])), 0, $ivlen);//openssl_random_pseudo_bytes($ivlen);
//$iv=1234567890123456;
        //$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $ciphertext_raw = openssl_encrypt($plaintext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        $hmac = ""; // hash_hmac('sha256', $ciphertext_raw, "123", $as_binary=true);
        $token = base64_encode($iv.$hmac.$ciphertext_raw);
        return str_replace(['+','/','='], ['-','_',''], $token);
    }
}