<?php
namespace ON\Service;

use League\Event\HasEventName;
use Symfony\Component\Dotenv\Dotenv;

class EnvLoader implements HasEventName {
    public function __invoke()
    {
        // load .env file
        $dotenv = new Dotenv();
        if(file_exists(".env")) {
            $dotenv->load(".env");
        }
    }

    public function eventName(): string {
        return "on.init";
    }
}