<?php

namespace FluentBoards\Framework\Http;

class Client
{
	protected function request($url, $args = [])
	{
		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			throw new class(
				$response->get_error_message(), 500
			) extends \Exception {};
		}

		return $this->makeResponse($response);
	}

	protected function makeResponse($response)
	{
		return new class($response) {

			protected $response = null;
			
			public function __construct($response) {
				$this->response = $response;
			}

			public function toArray() {
				return $this->response;
			}

			public function isOkay() {
				return $this->getCode() == 200;
			}

			public function throw() {
				$class = sprintf(
					'WpOrg\Requests\Exception\Http\Status%d', $this->getCode()
				);
				
				if (!class_exists($class)) {
					$class = 'WpOrg\Requests\Exception\Http\Status\Http';
				}
				
				throw new $class;
			}

			public function throwIf(callable $callback) {
				if ($callback($this)) {
					return $this->throw();
				}
			}

			public function getCode() {
				return wp_remote_retrieve_response_code($this->response);
			}

			public function getMessage() {
				return wp_remote_retrieve_response_message($this->response);
			}

			public function getBody() {
				return wp_remote_retrieve_body($this->response);
			}

			public function isJson() {
				$header = $this->getHeader('content-type');
				return str_contains($header, 'application/json');
			}

			public function getJson() {
				if ($this->isJson()) {
					return json_decode($this->getBody(), true);
				}
			}

			public function getHeaders() {
				return wp_remote_retrieve_headers($this->response);
			}

			public function getHeader($key) {
				return wp_remote_retrieve_header($this->response, $key);
			}

			public function getCookies() {
				return wp_remote_retrieve_cookies($this->response);
			}

			public function getCookie($name, $isObject = false) {
				if ($isObject) {
					// Return the WP_Http_Cookie object
					return wp_remote_retrieve_cookie($this->response, $name);
				}
				return wp_remote_retrieve_cookie_value($this->response, $name);
			}
		};
	}

	public static function __callStatic($method, $args)
	{
		return (new static)->request(array_shift($args), array_merge(
			count($args) ? $args : [], ['method' => strtoupper($method)]
		));
	}
}
