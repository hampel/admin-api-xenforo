<?php namespace Hampel\AdminApi\Check;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Fault\Manager as FaultManager;
use Hampel\AdminApi\ValueShape;
use XF\App;

/**
 * What admin-api:validate reports, kept apart from the command so that it is one caller of these
 * checks and not the only one.
 *
 * Each method returns rows of [outcome, label, detail], where the outcome is ok, warn, fail or
 * skip. A row is a fact about this add-on on this forum - whether it can do what it is for - and
 * never about the forum's health, which is what the endpoints are for.
 */
class HealthCheck
{
	const OK = 'ok';
	const WARN = 'warn';
	const FAIL = 'fail';
	const SKIP = 'skip';

	/**
	 * What these checks expect to find. Read through static::, like the other two lists below, so
	 * that a test can name one that is not there.
	 */
	const SCOPES = AbstractController::SCOPES;

	const PERMISSIONS = [
		'hampelAdminApiErrorLog',
		'hampelAdminApiErrorDetail',
		'hampelAdminApiAddOns',
		'hampelAdminApiJobs',
		'hampelAdminApiOptions',
		'hampelAdminApiConfig',
		'hampelAdminApiOnline',
		'hampelAdminApiValues',
	];

	/** The permissions a key with the read scope can use; the detail endpoint has a scope of its own. */
	const READ_PERMISSIONS = [
		'hampelAdminApiErrorLog',
		'hampelAdminApiAddOns',
		'hampelAdminApiJobs',
		'hampelAdminApiOptions',
		'hampelAdminApiConfig',
		'hampelAdminApiOnline',
	];

	const ROUTES = ['error-log', 'error-entries', 'add-ons', 'cron', 'jobs', 'options', 'config', 'online'];

	/** @var App */
	protected $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	/**
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function api()
	{
		if (!$this->app->config('enableApi'))
		{
			return [[self::FAIL, 'REST API', 'switched off in config.php (enableApi), so no endpoint answers']];
		}

		return [[self::OK, 'REST API', 'enabled']];
	}

	/**
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function scopes()
	{
		$rows = [];

		foreach (static::SCOPES AS $scopeId)
		{
			$scope = $this->app->em()->find('XF:ApiScope', $scopeId);

			if (!$scope)
			{
				$rows[] = [self::FAIL, $scopeId, 'not defined - no key can be given it'];
			}
			else if (!$scope->isValidColumn('usable_with_oauth_clients'))
			{
				// XenForo 2.2 has no OAuth clients to offer a scope to
				$rows[] = [self::OK, $scopeId, 'defined'];
			}
			else if ($scope->usable_with_oauth_clients)
			{
				$rows[] = [self::WARN, $scopeId, 'offered to OAuth clients; the endpoints refuse a token regardless'];
			}
			else
			{
				$rows[] = [self::OK, $scopeId, 'defined, and withheld from OAuth clients'];
			}
		}

		return $rows;
	}

	/**
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function permissions()
	{
		$defined = $this->app->db()->fetchAllColumn(
			'SELECT admin_permission_id FROM xf_admin_permission WHERE addon_id = ?',
			'Hampel/AdminApi'
		);
		$missing = array_diff(static::PERMISSIONS, $defined);

		if ($missing)
		{
			return [[self::FAIL, 'admin permissions', 'not defined: ' . implode(', ', $missing)]];
		}

		return [[self::OK, 'admin permissions', count(static::PERMISSIONS) . ' defined']];
	}

	/**
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function routes()
	{
		$routes = $this->app->db()->fetchPairs(
			"SELECT sub_name, controller FROM xf_route WHERE route_type = 'api' AND route_prefix = 'admin-api'"
		);
		$problems = [];

		foreach (static::ROUTES AS $name)
		{
			if (!isset($routes[$name]))
			{
				$problems[] = "$name is not registered";
			}
			else if (!class_exists($routes[$name]))
			{
				$problems[] = "$name names a controller that does not exist";
			}
		}

		if ($problems)
		{
			return [[self::FAIL, 'routes', implode('; ', $problems)]];
		}

		return [[self::OK, 'routes', count(static::ROUTES) . ' registered, each with its controller']];
	}

	/**
	 * Whether anybody can actually use it. With no key that can read, nothing is broken and the
	 * add-on does nothing, which is a warning: it works now and is no use until someone acts.
	 *
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function access()
	{
		$rows = [];
		$readers = 0;
		$detailReaders = 0;
		$valueReaders = 0;

		foreach ($this->getKeys() AS $key)
		{
			foreach ($key['scopes'] AS $scopeId)
			{
				$reason = $this->whyKeyCannotUse($key, $scopeId);

				if ($reason !== null)
				{
					$rows[] = [self::WARN, "key #{$key['api_key_id']}", "holds $scopeId and cannot use it: $reason"];
				}
				else if ($scopeId === AbstractController::SCOPE)
				{
					$readers++;
				}
				else if ($scopeId === AbstractController::SCOPE_VALUES)
				{
					$valueReaders++;
				}
				else
				{
					$detailReaders++;
				}
			}
		}

		array_unshift(
			$rows,
			$readers
				? [self::OK, 'keys that can read', (string) $readers]
				: [self::WARN, 'keys that can read', 'none - no API key can reach any endpoint yet'],
			$detailReaders
				? [self::OK, 'keys for error entries', (string) $detailReaders]
				: [self::SKIP, 'keys for error entries', 'none, which is the safe default'],
			$valueReaders
				? [self::OK, 'keys that see values', (string) $valueReaders]
				: [self::SKIP, 'keys that see values', 'none - options and config.php are reported by shape only']
		);

		return $rows;
	}

	/**
	 * Every API key that names one of this add-on's scopes. A key that merely allows all scopes
	 * was not made for this, and is left out.
	 *
	 * @return list<array<string, mixed>> api_key_id, title, key_type, active, user_id, username,
	 *                                    scopes, last_use_date, is_admin, admin_permissions (null
	 *                                    for a super administrator, who has them all)
	 */
	public function getKeys()
	{
		$keys = [];

		foreach ($this->app->finder('XF:ApiKey')->order('api_key_id')->fetch() AS $key)
		{
			$scopes = array_values(array_intersect(static::SCOPES, array_keys(array_filter((array) $key->scopes))));
			if (!$scopes)
			{
				continue;
			}

			$user = $key->user_id ? $this->app->em()->find('XF:User', $key->user_id) : null;
			$admin = $user && $user->is_admin ? $user->Admin : null;

			$keys[] = [
				'api_key_id' => $key->api_key_id,
				'title' => $key->title,
				'key_type' => $key->key_type,
				'active' => (bool) $key->active,
				'user_id' => $key->user_id,
				'username' => $user ? $user->username : null,
				'scopes' => $scopes,
				'last_use_date' => $key->last_use_date,
				'is_admin' => (bool) $admin,
				'admin_permissions' => !$admin || $admin->is_super_admin
					? null
					: array_keys(array_filter((array) $admin->permission_cache)),
			];
		}

		return $keys;
	}

	/**
	 * @param array<string, mixed> $key As getKeys() returns it
	 * @param string $scopeId
	 *
	 * @return string|null Null when it can
	 */
	protected function whyKeyCannotUse(array $key, $scopeId)
	{
		if (!$key['active'])
		{
			return 'the key is inactive';
		}

		if ($key['key_type'] !== 'user')
		{
			return "it is a {$key['key_type']} key, and only a user key is accepted";
		}

		if (!$key['is_admin'])
		{
			return 'its user is not an administrator';
		}

		// it adds to the read scope and does nothing without it, whoever holds the key
		if ($scopeId === AbstractController::SCOPE_VALUES && !in_array(AbstractController::SCOPE, $key['scopes'], true))
		{
			return 'it needs the ' . AbstractController::SCOPE . ' scope as well';
		}

		// a super administrator holds every permission
		if ($key['admin_permissions'] === null)
		{
			return null;
		}

		if ($scopeId === AbstractController::SCOPE_VALUES)
		{
			if (!in_array(AbstractController::PERMISSION_VALUES, $key['admin_permissions'], true))
			{
				return 'its user lacks ' . AbstractController::PERMISSION_VALUES;
			}

			// values come from the options and config.php endpoints, so one of those as well
			return array_intersect(['hampelAdminApiOptions', 'hampelAdminApiConfig'], $key['admin_permissions'])
				? null
				: 'its user lacks hampelAdminApiOptions and hampelAdminApiConfig';
		}

		if ($scopeId === AbstractController::SCOPE_ERROR_DETAIL)
		{
			$missing = array_diff(['hampelAdminApiErrorDetail', 'viewLogs'], $key['admin_permissions']);

			return $missing ? 'its user lacks ' . implode(' and ', $missing) : null;
		}

		return array_intersect(self::READ_PERMISSIONS, $key['admin_permissions'])
			? null
			: 'its user holds none of the admin permissions';
	}

	/**
	 * Runs what each endpoint runs, without a request. Anything thrown is the caller's to catch.
	 *
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function readers()
	{
		$now = \XF::$time;

		$errors = $this->app->repository('Hampel\AdminApi:ErrorLog')->getSummary($now - 86400, $now, 1);
		$jobs = $this->app->repository('Hampel\AdminApi:Job')->getState($now);
		$cron = $this->app->repository('Hampel\AdminApi:Cron')->getState($now);
		$settings = (new ValueShape())->flatten($this->app->config());
		$online = $this->app->repository('Hampel\AdminApi:Online')->getState($now);

		return [
			[self::OK, 'error log', "read: {$errors['total']} in the last day, {$errors['log']['total']} in the log"],
			[self::OK, 'job queue', "read: {$jobs['total']} queued, {$jobs['failed']['total']} failed on record"],
			[self::OK, 'cron entries', "read: {$cron['total']} entries"],
			[self::OK, 'add-ons', 'read: ' . count($this->app->addOnManager()->getAllAddOns()) . ' known'],
			[self::OK, 'options', 'read: ' . $this->app->finder('XF:Option')->total() . ' options'],
			[self::OK, 'config.php', 'read: ' . count($settings) . ' settings'],
			[self::OK, 'who is online', "read: {$online['total']} sessions in the last {$online['window_minutes']} minutes"],
		];
	}

	/**
	 * Faults belong on a test forum. Enabled, or planted, is a warning wherever it is found, so
	 * that a production forum which has either says so every time this runs.
	 *
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function faults()
	{
		$manager = new FaultManager($this->app);
		$batches = array_keys($manager->getBatches());
		$rows = [];

		$rows[] = $manager->isEnabled()
			? [self::WARN, 'fault commands', 'enabled in config.php - right for a test forum, wrong anywhere else']
			: [self::OK, 'fault commands', 'not enabled'];

		$rows[] = $batches
			? [self::WARN, 'planted faults', implode(', ', $batches) . ' - remove with admin-api:fault-clean']
			: [self::OK, 'planted faults', 'none'];

		return $rows;
	}

	/**
	 * Logs one error, checks the summary can see it, and takes it out again. The only proof that
	 * what the forum logs is what the endpoints read.
	 *
	 * @param string $prefix What every error log row this add-on writes begins with
	 *
	 * @return list<array{string, string, string}> Rows of outcome, label and detail
	 */
	public function probe($prefix)
	{
		$db = $this->app->db();
		$runId = bin2hex(random_bytes(6));
		$message = "{$prefix}admin-api:validate probe $runId - written to be read back, then removed";
		$before = (int) $db->fetchOne('SELECT COALESCE(MAX(error_id), 0) FROM xf_error_log');

		\XF::logError($message, true);

		$errorId = (int) $db->fetchOne(
			'SELECT error_id FROM xf_error_log WHERE error_id > ? AND message LIKE ?',
			[$before, '%' . $runId . '%']
		);

		if (!$errorId)
		{
			return [[
				self::WARN,
				'probe error',
				'XenForo logged nothing for an error raised from the command line (2.3.0 does not), so reading it back was not tested',
			]];
		}

		try
		{
			$summary = $this->app->repository('Hampel\AdminApi:ErrorLog')->getSummary(\XF::$time - 60, time() + 60, 100);
			$seen = in_array($errorId, array_column($summary['signatures'], 'last_error_id'), true);
		}
		finally
		{
			$db->delete('xf_error_log', 'error_id = ?', $errorId);
		}

		return [$seen
			? [self::OK, 'probe error', 'logged, read back through the summary, and removed']
			: [self::FAIL, 'probe error', 'logged, but the summary did not report it']];
	}
}
