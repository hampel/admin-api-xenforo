<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Report as ReportRepository;

/**
 * Reports waiting for a moderator, in counts: how many, of what kind, and for how long.
 *
 * @api-group Admin API
 */
class Reports extends AbstractController
{
	protected function getScope()
	{
		return self::SCOPE_REPORTS;
	}

	/**
	 * @api-desc Returns how many reports are open or assigned, by content type and by age, the
	 *   oldest and newest, the longest any has gone without activity, and the count moderators are
	 *   shown. Counts only: no content, no comment and no member. Requires a user API key with the
	 *   hampel_admin_api:reports scope selected, whose user holds the hampelAdminApi admin
	 *   permission.
	 *
	 * @api-out int $total Reports open or assigned
	 * @api-out int $open Reports open and not assigned
	 * @api-out int $assigned Reports assigned to a moderator
	 * @api-out array $by_age Reports first made under an hour, under a day, under a week ago, and older
	 * @api-out array $by_content_type Each type's count, assigned, oldest_date, newest_date and oldest_activity_date
	 * @api-out int|null $cached_total The count core keeps for moderators' navigation
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		/** @var ReportRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Report');

		return $this->apiResult($repo->getState(\XF::$time));
	}
}
