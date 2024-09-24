<?php

namespace FluentBoards\App\Api;

final class FBSApi
{
    private $instance = null;

    public function __construct($instance)
    {
        $this->instance = $instance;
    }

    public function __call($method, $params)
    {
        try {
            return call_user_func_array([$this->instance, $method], $params);

        } catch (\Exception $e) {
            return null;
        }
    }
}
