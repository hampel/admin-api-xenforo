<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Job as JobRepository;

/**
 * @api-group Admin API
 */
class Jobs extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiJobs';
	}

	/**
	 * @api-desc Summarises the job queue by job class: how many are queued, due, stalled, waiting
	 *   to be run manually or being retried, and the failed jobs XenForo has recorded. Failed
	 *   jobs are grouped by where they failed. Returns no job data, unique key or exception text. Requires a user API key holding the
	 *   hampel_admin_api:read scope, whose user has the hampelAdminApiJobs admin permission.
	 *
	 * @api-out int $now The time the state was read
	 * @api-out int $total Jobs in the queue
	 * @api-out int $manual Jobs that only run when triggered from the admin control panel
	 * @api-out int $due Automatic jobs whose trigger date has passed
	 * @api-out int $stalled Automatic jobs more than an hour past their trigger date
	 * @api-out int $retrying Jobs that have been attempted at least once
	 * @api-out int $oldest_due_date The earliest trigger date among due jobs, or null
	 * @api-out array $by_class The same counts for each job class
	 * @api-out array $failed Failed jobs: whether they are recorded, total, oldest_date, newest_date, by_class, and signatures by exception class, file and line
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		/** @var JobRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Job');

		return $this->apiResult($repo->getState(\XF::$time));
	}
}
