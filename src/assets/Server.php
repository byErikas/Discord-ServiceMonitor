<?php

namespace Erikas\DiscordServiceMonitor\Assets;

class Server
{
    private string $name;
    private string $address;
    private bool $online;

    function __construct(string $name, string $address, bool $online = false)
    {
        $this->name = $name;
        $this->address = $address;
        $this->online = $online;
    }

    public function set(string $key, string|bool $value)
    {
        $this->$key = $value;
    }

    public function get(string $key)
    {
        return $this->$key;
    }

    public function __toString(): string
    {
        if ($this->online) {
            $message = "🟢 ";
        } else {
            $message = "🔴 ";
        }

        return $message . "{$this->name}\n🖥️ {$this->address}";
    }
}
