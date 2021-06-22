<?php

namespace SurfingCrab\AgnoPay\DataModels;

class PhpSessionCacher implements CacherInterface {
    protected $prefix;

    const DEFAULT_KEY_PREFIX = 'agno_cacher_';

    public function __construct($prefix = self::DEFAULT_KEY_PREFIX) {
        $this->prefix = $prefix;
    }

    public function get($key): string {
        if($this->has($key)) {
            return "{$_SESSION[self::DEFAULT_KEY_PREFIX . $key]}";
        }
    }

    public function has($key): bool {
        return isset($_SESSION[self::DEFAULT_KEY_PREFIX . $key]) && is_scalar($_SESSION[self::DEFAULT_KEY_PREFIX . $key]);
    }

    public function forget($key) {
        if(isset($_SESSION[self::DEFAULT_KEY_PREFIX . $key])) {
            unset($_SESSION[self::DEFAULT_KEY_PREFIX . $key]);
        }
    }

    public function put($key, string $value) {
        if(!is_scalar($value)) {
            throw new \InvalidArgumentException(__class__ . ' can only cache scalar values.');
        }

        $_SESSION[self::DEFAULT_KEY_PREFIX . $key] = "$value";
    }
}