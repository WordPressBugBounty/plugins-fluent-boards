<?php

namespace FluentBoards\Framework\Http;

class Cookie
{    
    public static function set(
        $name,
        $value,
        $minutes = 0,
        $path = '',
        $domain = '',
        $secure = false,
        $httponly = false
    )
    {
        $time = ($minutes == 0) ? 0 : static::expiresAt($minutes * 60);
        
        setcookie($name, $value, $time, $path, $domain, $secure, $httponly);
    }

    public static function setForever(
        $name,
        $value,
        $path = '',
        $domain = '',
        $secure = false,
        $httponly = false
    )
    {
        $fiveYears = static::expiresAt((365 * 24 * 60 * 60) * 5);

        setcookie($name, $value, $fiveYears, $path, $domain, $secure, $httponly);
    }

    public static function get($name, $default = null)
    {
        if (array_key_exists($name, $_COOKIE)) {
            return $_COOKIE[$name];
        }

        return $default;
    }

    public static function delete($name, $path = '', $domain = '') {
        setcookie($name, '', time() - 3600, $path, $domain);

        if (array_key_exists($name, $_COOKIE)) {
            unset($_COOKIE[$name]);
        }
    }

    protected static function expiresAt($value = 0)
    {
        if (!$value instanceof \DateTimeInterface) {

            $value = is_numeric($value) ? (int) $value : 0;

            $value = new \DateTime('+' . $value . ' seconds');
        }

        return $value->getTimestamp();
    }
}
