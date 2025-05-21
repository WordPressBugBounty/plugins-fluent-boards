<?php

namespace FluentBoards\Framework\Support;

use stdClass;
use ArrayAccess;

class StdObject
{
	/**
	 * Creates an stdClass from an array
	 * 
	 * @param  array $array
	 * @return stdClass
	 */
	public static function create(array $array)
	{
		$object = new stdClass;
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $object->{$key} = call_user_func(__METHOD__, $value);
            } else {
                $object->{$key} = $value;
            }
        }

        return $object;
	}

	/**
     * Get an item from an object using "dot" notation.
     *
     * @template TValue of object
     *
     * @param  TValue  $object
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is empty ? TValue : mixed)
     */
    public static function get($object, $key, $default = null)
	{
	    if (is_null($key) || trim($key) === '') {
	        return $object;
	    }

	    foreach (explode('.', $key) as $segment) {
	        if (is_object($object) && isset($object->{$segment})) {
	            $object = $object->{$segment};
	        } elseif (
	        	(is_array($object) || $object instanceof ArrayAccess)
	        	&& isset($object[$segment])
	        ) {
	            $object = $object[$segment];
	        } else {
	            return $default;
	        }
	    }

	    return $object;
	}

	/**
	 * Transforms an stdClass to array
	 * 
	 * @param  stdClass $object
	 * @return array
	 */
	public static function toArray($object)
	{
		$array = [];

        foreach ($object as $key => $value) {
            if ($value instanceof stdClass) {
                $array[$key] = call_user_func(__METHOD__, $value);
            } else {
                $array[$key] = $value;
            }
        }

        return $array;
	}
}
