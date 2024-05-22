<?php

namespace Newms87\DanxLaravel\Requests;

use Illuminate\Http\Request;

class PagerRequest
{
	public Request $request;

	public function __construct(Request $request)
	{
		$this->request = $request;
	}

	public function has($key)
	{
		return $this->request->has($key) || $this->request->json()->has($key);
	}

	public function get($key, $default = null)
	{
		if ($this->request->json()->has($key)) {
			return $this->request->json($key);
		}

		return $this->request->get($key, $default);
	}

	public function filter()
	{
		return $this->getJson('filter');
	}

	public function sort()
	{
		return $this->getJson('sort');
	}

	public function input()
	{
		return $this->getJson('input');
	}

	public function getJson($field)
	{
		if ($this->has($field)) {
			$value = $this->get($field);
			if (is_string($value)) {
				return json_decode($value, true);
			} else {
				return $value;
			}
		}

		return null;
	}

	public function getJsonOrArray($field, $separator = ',')
	{
		$json = $this->getJson($field);
		if ($json) {
			return $json;
		}
		$value = $this->get($field);

		if (is_string($value)) {
			return explode($separator, $value);
		}

		return $value;
	}

	public function perPage($default = 10)
	{
		return $this->get('perPage', $default);
	}

	public function page()
	{
		return $this->get('page', 1);
	}

	public function validate(...$params)
	{
		return $this->request->validate(...$params);
	}
}
