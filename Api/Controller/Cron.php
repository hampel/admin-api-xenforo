<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Cron as CronRepository;

/**
 * @api-group Admin API
 */
class Cron extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiJobs';
	}

	/**
	 * @api-desc Lists every cron entry with when it is next due and whether it is overdue, and
	 *   when the job that runs them last ran. Requires a user API key holding the
	 *   hampel_admin_api:read scope, whose user has the hampelAdminApiJobs admin permission.
	 *
	 * @api-out int $now The time the state was read
	 * @api-out int $total Cron entries defined
	 * @api-out int $active Entries that are enabled and belong to an active add-on
	 * @api-out int $overdue Active entries more than an hour past their next run
	 * @api-out int $oldest_overdue_date The earliest next run among overdue entries, or null
	 * @api-out array $runner The job that runs cron entries: queued, next_run and last_run_date
	 * @api-out array $entries One entry per cron entry, ordered by next run
	 */
	public function actionGet()
	{
		/** @var CronRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Cron');

		return $this->apiResult($repo->getState(\XF::$time));
	}
}
