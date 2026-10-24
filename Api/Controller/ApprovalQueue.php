<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\ApprovalQueue as ApprovalQueueRepository;

/**
 * The approval queue in counts: how much is waiting, of what kind, and for how long.
 *
 * @api-group Admin API
 */
class ApprovalQueue extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiApproval';
	}

	/**
	 * @api-desc Returns how many items wait in the approval queue, by content type and by age,
	 *   the oldest and newest, and the count moderators are shown. Counts only: no content and no
	 *   member. Requires a user API key holding the hampel_admin_api:read scope, whose user has
	 *   the hampelAdminApiApproval admin permission.
	 *
	 * @api-out int $total Items waiting
	 * @api-out array $by_age Items waiting under an hour, under a day, under a week, and older
	 * @api-out array $by_content_type Each type's count, oldest_date and newest_date
	 * @api-out int|null $cached_total The count core keeps for moderators' navigation
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		/** @var ApprovalQueueRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:ApprovalQueue');

		return $this->apiResult($repo->getState(\XF::$time));
	}
}
