<?php

if(!function_exists('lowerAssocKeys')) {
    function lowerAssocKeys($input)
    {
        return array_combine(array_map('strtolower', array_keys($input)), array_values($input));
    }
}

if (! function_exists('my_value')) {
    function my_value($value)
    {
        return $value instanceof Closure ? $value() : $value;
    }
}

if (!function_exists('my_array_get')) {
    function my_array_get($array, $key, $default = null)
    {
        if (! my_accessible($array)) {
            return my_value($default);
        }
        
        if (is_null($key)) {
            return $array;
        }
        
        if (my_exists($array, $key)) {
            return $array[$key];
        }
        
        foreach (explode('.', $key) as $segment) {
            if (my_accessible($array) && my_exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return my_value($default);
            }
        }
        
        return $array;
    }
}

if (!function_exists('my_accessible')) {
    function my_accessible($value)
    {
        return is_array($value) || $value instanceof \ArrayAccess;
    }
}

if (!function_exists('my_exists')) {
    function my_exists($array, $key)
    {
        if ($array instanceof \ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }
}