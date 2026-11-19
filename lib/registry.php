<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

class AbilitiesError extends RuntimeException {
	private const HTTP_CODES = [
		'INVALID_ARGUMENT'    => 400,
		'FAILED_PRECONDITION' => 400,
		'OUT_OF_RANGE'        => 400,
		'UNAUTHENTICATED'     => 401,
		'PERMISSION_DENIED'   => 403,
		'NOT_FOUND'           => 404,
		'ALREADY_EXISTS'      => 409,
		'ABORTED'             => 409,
		'RESOURCE_EXHAUSTED'  => 429,
		'UNIMPLEMENTED'       => 501,
		'UNAVAILABLE'         => 503,
		'INTERNAL'            => 500,
	];

	/**
	 * @param string $status     A google.rpc.Code name.
	 * @param string $reason     UPPER_SNAKE_CASE ErrorInfo reason.
	 * @param string $message    Developer facing text; empty selects the default for the reason.
	 * @param array  $violations List of ['field' => string, 'description' => string].
	 * @param ?int   $httpCode   Overrides the HTTP status implied by $status.
	 */
	public function __construct(public string $status, public string $reason, string $message = '',
		public array $violations = [], public ?int $httpCode = null) {
		parent::__construct($message);
	}

	public function httpStatus() : int {
		return $this->httpCode ?? self::HTTP_CODES[$this->status] ?? 500;
	}
}

/** A deliberately small schema dialect. Unsupported constraints fail closed. */
function abilities_check_schema(array $schema) : void {
	$types = [
		'object'  => ['type', 'properties', 'required', 'additionalProperties'],
		'integer' => ['type', 'minimum', 'maximum'],
		'string'  => ['type', 'minLength', 'maxLength', 'enum'],
		'boolean' => ['type']
	];
	$type = $schema['type'] ?? '';

	if (!isset($types[$type]) || array_diff(array_keys($schema), $types[$type])) {
		throw new LogicException('Unsupported ability schema');
	}

	if ($type === 'object') {
		if (($schema['additionalProperties'] ?? null) !== false || !isset($schema['properties'])) {
			throw new LogicException('Object schemas must be closed');
		}

		foreach ($schema['properties'] as $property) {
			abilities_check_schema($property);
		}

		foreach ($schema['required'] ?? [] as $name) {
			if (!array_key_exists($name, (array) $schema['properties'])) {
				throw new LogicException('Unknown required property');
			}
		}
	}
}

function abilities_validate(mixed $value, array $schema) : void {
	$type  = $schema['type'];
	$valid = ($type === 'object' && $value instanceof stdClass)
		|| ($type === 'integer' && is_int($value))
		|| ($type === 'string' && is_string($value))
		|| ($type === 'boolean' && is_bool($value));

	if (!$valid) {
		throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_ARGUMENTS');
	}

	if ($type === 'object') {
		$properties = (array) $schema['properties'];

		foreach ($schema['required'] ?? [] as $name) {
			if (!property_exists($value, $name)) {
				throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_ARGUMENTS');
			}
		}

		foreach (get_object_vars($value) as $name => $item) {
			if (!array_key_exists($name, $properties)) {
				throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_ARGUMENTS');
			}
			abilities_validate($item, $properties[$name]);
		}
	}

	if ($type === 'integer' && ($value < ($schema['minimum'] ?? PHP_INT_MIN)
		|| $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
		throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_ARGUMENTS');
	}

	if ($type === 'string') {
		$length = preg_match_all('/./us', $value);

		if ($length === false || $length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? 4096)
			|| (isset($schema['enum']) && !in_array($value, $schema['enum'], true))) {
			throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_ARGUMENTS');
		}
	}
}

class AbilitiesRegistry {
	private array $entries = [];

	public function register(string $name, string $description, array $schema, callable $authorize, callable $execute) : void {
		if (count($this->entries) >= 256 || strlen($description) > 4096
			|| !preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $name) || isset($this->entries[$name])
			|| ($schema['type'] ?? '') !== 'object') {
			throw new LogicException('Invalid or duplicate ability');
		}
		abilities_check_schema($schema);
		$this->entries[$name] = compact('name', 'description', 'schema', 'authorize', 'execute');
	}

	public function discover(array $principal) : array {
		$items = [];

		foreach ($this->entries as $entry) {
			if ($this->allowed($entry, $principal)) {
				$items[] = [
					'name'        => $entry['name'],
					'description' => $entry['description'],
					'inputSchema' => $entry['schema'],
					'readOnly'    => true
				];
			}
		}

		return $items;
	}

	public function execute(array $principal, string $name, stdClass $arguments, callable $audit) : array {
		$entry = $this->entries[$name] ?? null;

		if ($entry === null || !$this->allowed($entry, $principal)) {
			throw new AbilitiesError('PERMISSION_DENIED', 'ABILITY_UNAVAILABLE');
		}
		abilities_validate($arguments, $entry['schema']);
		$id = bin2hex(random_bytes(16));
		$audit($id, $name, 'started');

		try {
			$result = ($entry['execute'])($principal['user_id'], $arguments);

			if (!is_array($result) || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 1000000) {
				throw new AbilitiesError('INTERNAL', 'INVALID_RESULT');
			}
		} catch (Throwable $error) {
			$audit($id, $name, 'failed');

			throw $error;
		}
		$audit($id, $name, 'succeeded');

		return ['requestId' => $id, 'result' => (object) $result];
	}

	private function allowed(array $entry, array $principal) : bool {
		return in_array($entry['name'], $principal['scopes'], true)
			&& ($entry['authorize'])($principal['user_id']) === true;
	}
}
