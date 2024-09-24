<?php

namespace FluentBoards\Framework\Http;

use Exception;
use FluentBoards\Framework\Support\DateTime;

class URL
{
	protected $encrypter = null;

	public function __construct($encrypter)
	{
		$this->encrypter = $encrypter;
	}

	/**
	 * Get the current URL
	 * 
	 * @return string
	 */
	public function current()
	{
		return get_site_url() . rtrim($_SERVER['REQUEST_URI'], '/');
	}

	/**
	 * Parse a url from uncompiled route
	 * 
	 * @param  string $url
	 * @param  array  $params
	 * @return string
	 */
	public function parse(string $url, array $params)
	{
		$pattern = '#\{[a-zA-Z0-9-_]+\}#';
		
	    return preg_replace_callback($pattern, function($matches) use ($params) {
	        foreach ($matches as $match) {
	            $match = str_replace(['{', '}'], ['', ''], $match);
	            if (isset($params[$match])) {
	                return $params[$match];
	            }
	        }
	    }, $url);
	}

	/**
	 * Sign the current url.
	 * 
	 * @param  array $params
	 * @return string
	 */
	public function signCurrentUrl($params = [])
	{
		return $this->sign($this->current(), $params);
	}

	/**
	 * Sign a URL
	 * 
	 * @param  string $url
	 * @return string
	 */
	public function sign($url, $params = [])
	{
		$parts = parse_url($url);

		$url = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

		parse_str($parts['query'] ?? '', $query);
		
		$params = $this->validateExpiryTime($params + $query);

        $params = $this->encrypter->encrypt(http_build_query($params));
        
        $signature = hash_hmac('sha256', $params, $this->encrypter->getKey());
        
        return $url . '?_data=' . $params . '&_signature=' . $signature;
	}

	/**
	 * Normalize the expiry time.
	 * 
	 * @param  array $params
	 * @return array
	 */
	public function validateExpiryTime($params)
	{
		if (isset($params['expires_at'])) {
			if (is_string($params['expires_at'])) {
				$params['expires_at'] = strtotime($params['expires_at']);
			} elseif ($params['expires_at'] instanceof DateTime) {
				$params['expires_at'] = $params['expires_at']->getTimestamp();
			}

			if (!is_numeric($params['expires_at']) || $params['expires_at'] < time()) {
				throw new Exception('The expiry time has passed or invalid.');
			}
		}

		return $params;
	}

	/**
	 * Validate a URL
	 * 
	 * @param  string $url
	 * @return mixed (false or array)
	 */
	public function validate($url)
	{
		if (!$query = $this->parseUrlAndGetQuery($url)) {
			return false;
		}

        if (!$query['_data'] || !$query['_signature']) {
            return false;
        }

        return $this->verifySignature($query['_data'], $query['_signature']);
	}

	/**
	 * Parse query string from the url.
	 * 
	 * @param  string $url
	 * @return mixed (bool or array)
	 */
	public function parseUrlAndGetQuery($url)
	{
		$parts = parse_url($url);

        if (!isset($parts['query'])) {
            return false;
        }

        parse_str($parts['query'], $query);

        return $query;
	}

	/**
	 * Verify the signature.
	 * 
	 * @param  array $data
	 * @param  string $signature
	 * @return mixed (false or array)
	 */
	public function verifySignature($data, $signature)
	{
		$expected = hash_hmac('sha256', $data, $this->encrypter->getKey());

        if (!hash_equals($expected, $signature)) return false;

        parse_str($this->encrypter->decrypt(urldecode($data)), $params);

        if (isset($params['expires_at']) && time() > $params['expires_at']) {
            return false;
        }

        return $params;
	}

	/**
	 * Returns the string representation of the URL object
	 * 
	 * @return string Current URL.
	 */
	public function __toString()
	{
		return $this->current();
	}
}
