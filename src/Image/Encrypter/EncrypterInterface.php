<?php
namespace ON\Image\Encrypter;

interface  EncrypterInterface {
    public function decrypt($token);
    public function encrypt($data);
}