<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Activity as ActivityRepository;

/**
 * Registrations, threads and posts made in a window, counted by the state each is in now.
 *
 * @api-group Admin API
 */
class Activity extends AbstractController
{
	const DEFAULT_WINDOW = 86400;

	/** Each count reads every row in the window, so the window is capped; a month is enough to compare against */
	const MAX_WINDOW_DAYS = 31;

	protected function getScope()
	{
		return self::SCOPE_ACTIVITY;
	}

	/**
	 * @api-desc Returns how many members registered, and how many threads and posts were made, in
	 *   the window, each by the state it is in now: a member valid, awaiting confirmation or
	 *   approval, rejected or disabled; content visible, awaiting approval or deleted. Counts only:
	 *   no member and no content. Requires a user API key with the hampel_admin_api:activity scope
	 *   selected, whose user holds the hampelAdminApi admin permission.
	 *
	 * @api-in int $since Start of the window, as a Unix timestamp, included. Defaults to 24 hours before until.
	 * @api-in int $until End of the window, as a Unix timestamp, excluded. Defaults to now + 1, so this second is in. At most 31 days after since; left out, since may be up to 31 days before now.
	 *
	 * @api-out array $window The window counted
	 * @api-out array $registrations The total, and by_state for each member state
	 * @api-out array $threads The total, and by_state for each content state
	 * @api-out array $posts The total, and by_state for each content state
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$since = $this->filter('since', '?uint');
		$until = $this->filter('until', '?uint');

		// the second added to a default end only takes in this second; it does not lengthen the window
		$capEnd = $until;
		if ($until === null)
		{
			$until = \XF::$time + 1;
			$capEnd = \XF::$time;
		}
		if ($since === null)
		{
			$since = max(0, $until - self::DEFAULT_WINDOW);
		}

		if ($capEnd - $since > self::MAX_WINDOW_DAYS * 86400)
		{
			return $this->error(\XF::phrase('hampel_adminapi_window_too_long', ['days' => self::MAX_WINDOW_DAYS]), 400);
		}

		/** @var ActivityRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Activity');

		return $this->apiResult($repo->getSummary($since, $until));
	}
}
