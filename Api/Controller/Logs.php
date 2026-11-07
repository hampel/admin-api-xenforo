<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Logs as LogsRepository;

/**
 * Counts from every log XenForo keeps: one line each, or one log in full.
 *
 * @api-group Admin API
 */
class Logs extends AbstractController
{
	const DEFAULT_WINDOW = 86400;

	protected function getScope()
	{
		return self::SCOPE_LOGS;
	}

	/**
	 * @api-desc Returns, for every log XenForo keeps, how many entries fall in the window and the
	 *   oldest and newest it holds; or, given a log, that log in full - members who acted, a series
	 *   by day and its breakdowns. Counts only: no entry, URL, address or message. Requires a user
	 *   API key with the hampel_admin_api:logs scope selected, whose user holds the hampelAdminApi
	 *   admin permission.
	 *
	 * @api-in str $log One log, as named in the list this returns without it
	 * @api-in int $since Start of the window, as a Unix timestamp, included. Defaults to 24 hours ago; 0 is the whole log.
	 * @api-in int $until End of the window, as a Unix timestamp, excluded. Defaults to now + 1, so this second is in.
	 *
	 * @api-out array $window The window counted
	 * @api-out array $logs Without log: each log's total in the window, and its log_oldest_date and log_newest_date
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$logId = $this->filter('log', 'str');
		$since = $this->filter('since', '?uint');
		$until = $this->filter('until', '?uint');

		if ($until === null)
		{
			$until = \XF::$time + 1;
		}
		if ($since === null)
		{
			$since = max(0, $until - self::DEFAULT_WINDOW);
		}

		/** @var LogsRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Logs');

		if ($logId === '')
		{
			return $this->apiResult($repo->getIndex($since, $until));
		}

		if (!in_array($logId, $repo->getLogIds(), true))
		{
			return $this->error(\XF::phrase('hampel_adminapi_unknown_log', ['logs' => implode(', ', $repo->getLogIds())]), 400);
		}

		return $this->apiResult($repo->getLog($logId, $since, $until));
	}
}
