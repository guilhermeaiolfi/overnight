<?php
namespace ON\Config;

use ON\Db\PdoDatabase;

class DatabaseConfig extends Config {

    public function addDatabase(
        string $name,
        ?string $dsn = null,
        ?array $attributes = [],
        ?string $username = "",
        ?string $password = "",
        ?string $class = PdoDatabase::class,
        ?string $wrapper_class = null
    )
    {
        $this->set("databases.{$name}", [
            "dsn" => $dsn,
            "attributes" => $attributes,
            "username" => $username,
            "password" => $password,
            "class" => $class,
            "wrapper_class" => $wrapper_class,
        ]);

        if (!$this->get('default')) {
            $this->set('default', $name);
        }
        return $this;
    }

    public function setDefault(string $name)
    {
        $this->set('default', $name);
        return $this;
    }

    public function getDefault()
    {
        $name = $this->get('default');
        return $this->get("databases.{$name}");
    }

    public function getDefaultName()
    {
        return $this->get('default');
    }
}