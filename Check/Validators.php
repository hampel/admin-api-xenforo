<?php namespace Hampel\AdminApi\Check;

use XF\App;

/**
 * The checks add-ons declare for the validate endpoint, and the running of them.
 *
 * An add-on declares its checks by answering the hampel_admin_api_validate event: a class, and
 * a fixed list of checks, each with an id, a label and the method that runs it. The answer is
 * built from that declaration and from the outcomes of the rows each check returns. A row's own
 * label and detail are free text the add-on wrote, and are counted, never returned.
 *
 * A check runs here only when it is marked api: quick, within the forum, and writing nothing.
 * Every other check is reported as skipped, which is never a pass. Anything a check throws is a
 * fail in the answer and nothing else: no error log entry, because a failing check would
 * otherwise write one on every poll, and whatever watches the log would hear of it.
 */
class Validators
{
	const EVENT = 'hampel_admin_api_validate';

	const OK = 'ok';
	const WARN = 'warn';
	const FAIL = 'fail';
	const SKIPPED = 'skipped';

	/** What a row's outcome is counted as, by what the add-on wrote; anything else is a fail */
	const ROW_OUTCOMES = [
		HealthCheck::OK => self::OK,
		HealthCheck::WARN => self::WARN,
		HealthCheck::FAIL => self::FAIL,
		HealthCheck::SKIP => self::SKIPPED,
	];

	const CHECK_ID_PATTERN = '/^[a-z0-9_]{1,50}$/';

	const LABEL_MAX_LENGTH = 100;

	/** @var App */
	protected $app;

	/** @var array<string, mixed>|null */
	protected $declarations;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	/**
	 * What the active add-ons declared, by add-on id, as they declared it. The event is fired once.
	 *
	 * @return array<string, mixed>
	 */
	public function getDeclarations()
	{
		if ($this->declarations === null)
		{
			// filled in by each listener through the reference, so nothing about its shape is known
			/** @var mixed[] $declared */
			$declared = [];
			$this->app->fire(self::EVENT, [&$declared]);

			$this->declarations = [];
			foreach ((array) $declared AS $addOnId => $declaration)
			{
				if (is_string($addOnId) && is_array($declaration))
				{
					$this->declarations[$addOnId] = $declaration;
				}
			}
		}

		return $this->declarations;
	}

	/**
	 * The add-ons that declare checks, as ids. Only an installed, active add-on is listed, so an
	 * entry made for an add-on that is not there does not appear.
	 *
	 * @return string[]
	 */
	public function getDeclaringAddOnIds()
	{
		$ids = [];
		$manager = $this->app->addOnManager();

		foreach (array_keys($this->getDeclarations()) AS $addOnId)
		{
			$addOn = $manager->getById($addOnId);
			if ($addOn && $addOn->isInstalled() && $addOn->isActive())
			{
				$ids[] = $addOnId;
			}
		}

		sort($ids);

		return $ids;
	}

	/**
	 * @param string $addOnId
	 *
	 * @return bool
	 */
	public function isDeclared($addOnId)
	{
		return isset($this->getDeclarations()[$addOnId]);
	}

	/**
	 * The checks an add-on declared, with only what the answer may carry: an id from the
	 * declaration, a label of bounded length, and whether it may run here. An entry whose id is
	 * not one this endpoint can return is left out and counted; an entry with a valid id and
	 * anything else wrong is kept, and fails when it is run.
	 *
	 * @param string $addOnId
	 *
	 * @return array{checks: array<string, array{label: string, method: string|null, api: bool}>, invalid: int}
	 */
	public function getChecks($addOnId)
	{
		$declaration = $this->getDeclarations()[$addOnId] ?? [];
		$entries = isset($declaration['checks']) && is_array($declaration['checks']) ? $declaration['checks'] : [];
		$checks = [];
		$invalid = 0;

		foreach ($entries AS $id => $entry)
		{
			if (!is_string($id) || !preg_match(self::CHECK_ID_PATTERN, $id))
			{
				$invalid++;
				continue;
			}

			$entry = is_array($entry) ? $entry : [];
			$label = $entry['label'] ?? null;
			$method = $entry['method'] ?? null;

			$labelValid = is_string($label) && $label !== '' && strlen($label) <= self::LABEL_MAX_LENGTH;

			// a check with no usable label stands under its id, and fails, so that it gets fixed
			$checks[$id] = [
				'label' => $labelValid ? $label : $id,
				'method' => $labelValid && is_string($method) && $method !== '' ? $method : null,
				'api' => ($entry['api'] ?? false) === true,
			];
		}

		return ['checks' => $checks, 'invalid' => $invalid];
	}

	/**
	 * Runs an add-on's checks that may run here, and reports each one's outcome, the rows it
	 * returned counted by outcome, and the exit codes its validate command would give.
	 *
	 * @param string $addOnId
	 *
	 * @return array{checks: list<array<string, mixed>>, invalid_checks: int, exit_code: int, exit_code_strict: int}
	 */
	public function run($addOnId)
	{
		$declared = $this->getChecks($addOnId);
		$instance = null;
		$instanceFailed = false;
		$results = [];
		$failed = $declared['invalid'] > 0;
		$warned = false;

		foreach ($declared['checks'] AS $id => $check)
		{
			$rows = [self::OK => 0, self::WARN => 0, self::FAIL => 0, self::SKIPPED => 0];

			if (!$check['api'])
			{
				$outcome = self::SKIPPED;
			}
			else
			{
				if ($instance === null && !$instanceFailed)
				{
					$instance = $this->instantiate($addOnId);
					$instanceFailed = $instance === null;
				}

				$returned = $instance && $check['method'] !== null ? $this->call($instance, $check['method']) : null;

				if ($returned === null)
				{
					$rows[self::FAIL]++;
				}
				else
				{
					foreach ($returned AS $row)
					{
						$rows[$this->rowOutcome($row)]++;
					}
				}

				$outcome = $this->worstOutcome($rows);
			}

			$failed = $failed || $outcome === self::FAIL;
			$warned = $warned || $outcome === self::WARN;

			$results[] = [
				'id' => $id,
				'label' => $check['label'],
				'outcome' => $outcome,
				'rows' => $rows,
			];
		}

		return [
			'checks' => $results,
			'invalid_checks' => $declared['invalid'],
			'exit_code' => $failed ? 1 : 0,
			'exit_code_strict' => $failed ? 1 : ($warned ? 2 : 0),
		];
	}

	/**
	 * The add-on's class, constructed with the app. Only a class in the add-on's own namespace is
	 * accepted, so that one add-on cannot declare checks for another.
	 *
	 * @param string $addOnId
	 *
	 * @return object|null
	 */
	protected function instantiate($addOnId)
	{
		$class = $this->getDeclarations()[$addOnId]['class'] ?? null;
		$namespace = str_replace('/', '\\', $addOnId) . '\\';

		if (!is_string($class) || strpos(ltrim($class, '\\'), $namespace) !== 0)
		{
			return null;
		}

		try
		{
			if (!class_exists($class))
			{
				return null;
			}

			return new $class($this->app);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Calls one check. Null when it could not be called, threw, or did not return a list of rows.
	 *
	 * @param object $instance
	 * @param string $method
	 *
	 * @return array<mixed>|null
	 */
	protected function call($instance, $method)
	{
		if (!is_callable([$instance, $method]))
		{
			return null;
		}

		try
		{
			$returned = $instance->$method();
		}
		catch (\Throwable $e)
		{
			return null;
		}

		return is_array($returned) ? $returned : null;
	}

	/**
	 * @param mixed $row
	 *
	 * @return string
	 */
	protected function rowOutcome($row)
	{
		$outcome = is_array($row) && isset($row[0]) && is_string($row[0]) ? $row[0] : null;

		return $outcome !== null && isset(self::ROW_OUTCOMES[$outcome]) ? self::ROW_OUTCOMES[$outcome] : self::FAIL;
	}

	/**
	 * A check is as bad as its worst row. One whose rows were all skipped, or that returned none,
	 * did not pass: it is skipped.
	 *
	 * @param array<string, int> $rows
	 *
	 * @return string
	 */
	protected function worstOutcome(array $rows)
	{
		foreach ([self::FAIL, self::WARN, self::OK] AS $outcome)
		{
			if ($rows[$outcome] > 0)
			{
				return $outcome;
			}
		}

		return self::SKIPPED;
	}
}
