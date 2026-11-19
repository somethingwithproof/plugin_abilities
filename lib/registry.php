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
		'object'  => ['type', 'description', 'properties', 'required', 'additionalProperties'],
		'integer' => ['type', 'description', 'minimum', 'maximum'],
		'number'  => ['type', 'description', 'minimum', 'maximum'],
		'string'  => ['type', 'description', 'minLength', 'maxLength', 'enum'],
		'boolean' => ['type', 'description'],
		'array'   => ['type', 'description', 'items', 'maxItems']
	];
	$type = $schema['type'] ?? '';

	// A nullable type is a two-element list ending in 'null'.
	if (is_array($type)) {
		if (count($type) !== 2 || !array_is_list($type) || $type[1] !== 'null') {
			throw new LogicException('Unsupported ability schema');
		}
		$type = $type[0];
	}

	if (!is_string($type) || !isset($types[$type]) || array_diff(array_keys($schema), $types[$type])) {
		throw new LogicException('Unsupported ability schema');
	}

	if ($type === 'object') {
		if (($schema['additionalProperties'] ?? null) !== false || !isset($schema['properties'])) {
			throw new LogicException('Object schemas must be closed');
		}

		foreach ($schema['properties'] as $property) {
			if (!is_array($property)) {
				throw new LogicException('Unsupported ability schema');
			}
			abilities_check_schema($property);
		}

		foreach ($schema['required'] ?? [] as $name) {
			if (!array_key_exists($name, (array) $schema['properties'])) {
				throw new LogicException('Unknown required property');
			}
		}
	}

	if ($type === 'array') {
		if (!is_array($schema['items'] ?? null) || !is_int($schema['maxItems'] ?? null) || $schema['maxItems'] < 0) {
			throw new LogicException('Array schemas need items and maxItems');
		}
		abilities_check_schema($schema['items']);
	}
}

function abilities_validate(mixed $value, array $schema) : void {
	$type = $schema['type'];

	if (is_array($type)) {
		if ($value === null) {
			return;
		}
		$type = $type[0];
	}
	$valid = ($type === 'object' && $value instanceof stdClass)
		|| ($type === 'integer' && is_int($value))
		|| ($type === 'number' && (is_int($value) || is_float($value)))
		|| ($type === 'string' && is_string($value))
		|| ($type === 'boolean' && is_bool($value))
		|| ($type === 'array' && is_array($value) && array_is_list($value));

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

	if ($type === 'array') {
		if (count($value) > $schema['maxItems']) {
			throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_ARGUMENTS');
		}

		foreach ($value as $item) {
			abilities_validate($item, $schema['items']);
		}
	}

	if (($type === 'integer' || $type === 'number') && ($value < ($schema['minimum'] ?? -INF)
		|| $value > ($schema['maximum'] ?? INF))) {
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
	private const ID_PATTERN       = '/^[a-z][a-z0-9]*(-[a-z0-9]+){1,7}$/D';
	private const CATEGORY_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/D';
	private const ANNOTATIONS      = ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
	private const KEYS             = ['label', 'description', 'category', 'input_schema', 'output_schema', 'annotations', 'permission', 'execute'];

	private array $entries    = [];
	private array $categories = [];

	public function registerCategory(string $id, string $label) : void {
		if (!preg_match(self::CATEGORY_PATTERN, $id) || $label === '' || isset($this->categories[$id])) {
			throw new LogicException('Invalid or duplicate ability category');
		}
		$this->categories[$id] = $label;
	}

	public function register(string $id, array $definition) : void {
		if (strlen($id) > 64 || !preg_match(self::ID_PATTERN, $id) || isset($this->entries[$id]) || count($this->entries) >= 256) {
			throw new LogicException('Invalid or duplicate ability');
		}

		if (array_diff(array_keys($definition), self::KEYS) || array_diff(self::KEYS, array_keys($definition))) {
			throw new LogicException('Ability definition must have exactly the documented keys');
		}

		foreach (['label', 'description', 'category'] as $key) {
			if (!is_string($definition[$key])) {
				throw new LogicException('Ability ' . $key . ' must be a string');
			}
		}

		if ($definition['label'] === '' || strlen($definition['description']) > 4096 || !isset($this->categories[$definition['category']])) {
			throw new LogicException('Invalid ability label, description, or category');
		}

		if (!is_array($definition['annotations']) || !$this->sameAnnotations($definition['annotations'])) {
			throw new LogicException('Only read-only, non-destructive, idempotent abilities may register');
		}

		if (!is_callable($definition['permission']) || !is_callable($definition['execute'])) {
			throw new LogicException('Ability permission and execute must be callable');
		}

		foreach (['input_schema', 'output_schema'] as $key) {
			if (!is_array($definition[$key]) || ($definition[$key]['type'] ?? '') !== 'object') {
				throw new LogicException('Ability schemas must be object schemas');
			}
			abilities_check_schema($definition[$key]);
		}
		$this->entries[$id] = ['id' => $id] + $definition;
	}

	public function discover(array $principal) : array {
		$items = [];

		foreach ($this->entries as $entry) {
			if ($this->allowed($entry, $principal)) {
				$items[] = $this->descriptor($entry);
			}
		}

		return $items;
	}

	public function get(array $principal, string $id) : ?array {
		$entry = $this->entries[$id] ?? null;

		return $entry !== null && $this->allowed($entry, $principal) ? $this->descriptor($entry) : null;
	}

	/** @return array{requestId: string, result: array} */
	public function execute(array $principal, string $id, stdClass $args, callable $audit) : array {
		$entry = $this->entries[$id] ?? null;

		if ($entry === null || !$this->allowed($entry, $principal)) {
			throw new AbilitiesError('PERMISSION_DENIED', 'ABILITY_UNAVAILABLE');
		}
		abilities_validate($args, $entry['input_schema']);
		$requestId = bin2hex(random_bytes(16));
		$audit($requestId, $id, 'started');

		try {
			$result = ($entry['execute'])($principal['user_id'], $args);
			$this->checkResult($result, $entry['output_schema']);
		} catch (Throwable $error) {
			$audit($requestId, $id, 'failed');

			throw $error;
		}
		$audit($requestId, $id, 'succeeded');

		return ['requestId' => $requestId, 'result' => $result];
	}

	/** The result must survive a JSON round trip and match the declared output schema exactly. */
	private function checkResult(mixed $result, array $schema) : void {
		try {
			if (!is_array($result)) {
				throw new AbilitiesError('INTERNAL', 'INVALID_RESULT');
			}
			$json = json_encode((object) $result, JSON_THROW_ON_ERROR);

			if (strlen($json) > 1000000) {
				throw new AbilitiesError('INTERNAL', 'INVALID_RESULT');
			}
			abilities_validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schema);
		} catch (AbilitiesError $error) {
			throw new AbilitiesError('INTERNAL', 'INVALID_RESULT');
		} catch (JsonException $error) {
			throw new AbilitiesError('INTERNAL', 'INVALID_RESULT');
		}
	}

	private function sameAnnotations(array $annotations) : bool {
		foreach (self::ANNOTATIONS as $key => $value) {
			if (!array_key_exists($key, $annotations) || $annotations[$key] !== $value) {
				return false;
			}
		}

		return count($annotations) === count(self::ANNOTATIONS);
	}

	private function descriptor(array $entry) : array {
		return [
			'id'           => $entry['id'],
			'label'        => $entry['label'],
			'description'  => $entry['description'],
			'category'     => $entry['category'],
			'inputSchema'  => $entry['input_schema'],
			'outputSchema' => $entry['output_schema'],
			'annotations'  => self::ANNOTATIONS
		];
	}

	private function allowed(array $entry, array $principal) : bool {
		return in_array('abilities:read', $principal['scopes'], true)
			&& ($entry['permission'])($principal['user_id']) === true;
	}
}

/** Hands plugins a registry view that only accepts abilities in their own namespace. */
class AbilitiesRegistrar {
	public function __construct(private AbilitiesRegistry $registry, private string $namespace) {
	}

	public function register(string $id, array $definition) : void {
		if (!str_starts_with($id, $this->namespace . '-')) {
			throw new LogicException('Ability IDs must start with the plugin namespace');
		}
		$this->registry->register($id, $definition);
	}

	public function registerCategory(string $id, string $label) : void {
		$this->registry->registerCategory($id, $label);
	}
}
