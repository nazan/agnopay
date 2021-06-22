<?php

namespace SurfingCrab\AgnoPay\DataModels;

interface CacherInterface {
    public function get($key): string;
    public function has($key): bool;
    public function forget($key);
    public function put($key, string $value);
}