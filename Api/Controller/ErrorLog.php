<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\ErrorLog as ErrorLogRepository;

/**
 * @api-group Admin API
 */
class ErrorLog extends AbstractController
{
	const DEFAULT_WINDOW = 86400;
	const DEFAULT_LIMIT = 20;
	const MAX_LIMIT = 100;

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiErrorLog';
	}

	/**
	 * @api-desc Summarises the server error log over a time window: counts by exception type, by
	 *   add-on and by signature (type, file and line). Returns no message, stack trace, request
	 *   data, user or IP address. Requires a user API key holding the hampel_admin_api:read scope,
	 *   whose user has the hampelAdminApiErrorLog admin permission.
	 *
	 * @api-in int $since Start of the window, as a Unix timestamp. Defaults to 24 hours ago; 0 is the whole log.
	 * @api-in int $until End of the window, as a Unix timestamp. Defaults to now.
	 * @api-in int $limit Maximum number of signatures to return, most frequent first. Default 20, maximum 100.
	 *
	 * @api-out array $window The window summarised: since, until, and whether it starts before the log does
	 * @api-out int $total Errors logged in the window
	 * @api-out int $oldest_date Date of the oldest error in the window, or null
	 * @api-out int $newest_date Date of the newest error in the window, or null
	 * @api-out array $by_type Count and newest date for each exception type
	 * @api-out array $by_add_on Count and newest date for each add-on the errors were raised in
	 * @api-out array $signatures Count, first and last date for each type, file and line
	 * @api-out int $signature_count Distinct signatures in the window, before the limit
	 * @api-out array $log Total, oldest and newest date for the whole log, whatever the window
	 */
	public function actionGet()
	{
		$since = $this->filter('since', '?uint');
		$until = $this->filter('until', '?uint');
		$limit = $this->filter('limit', '?uint');

		if ($until === null)
		{
			$until = \XF::$time;
		}
		if ($since === null)
		{
			$since = max(0, $until - self::DEFAULT_WINDOW);
		}
		if (!$limit)
		{
			$limit = self::DEFAULT_LIMIT;
		}
		$limit = min($limit, self::MAX_LIMIT);

		/** @var ErrorLogRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:ErrorLog');

		return $this->apiResult($repo->getSummary($since, $until, $limit));
	}
}
