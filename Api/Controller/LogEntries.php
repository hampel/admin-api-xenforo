<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\LogEntries as LogEntriesRepository;
use XF\Mvc\ParameterBag;

/**
 * Entries from one of the logs that record what members and staff did. Member data, so a scope and
 * an admin permission of its own, core's permission to view logs, and a line in the admin log for
 * every lookup.
 *
 * @api-group Admin API
 */
class LogEntries extends AbstractController
{
	const DEFAULT_LIMIT = 50;

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiLogEntries';
	}

	protected function getScope()
	{
		return self::SCOPE_LOG_ENTRIES;
	}

	/**
	 * The control panel's logs take the view logs admin permission. The API is not a way round it.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		parent::preDispatchController($action, $params);

		$this->assertKeyUserAdminPermission('viewLogs');
	}

	/**
	 * @api-desc Returns entries from one log, newest first: who acted, the member it was about, when,
	 *   and the log's own closed values. Never an IP address, a reason, a title or a message; a
	 *   user change's values only when asked for. Every lookup is written to the admin log.
	 *   Requires a user API key holding the hampel_admin_api:log_entries scope, whose user has both
	 *   the hampelAdminApiLogEntries and the viewLogs admin permissions; the admin log needs a
	 *   super administrator as well.
	 *
	 * @api-in str $log admin, moderator, user_change, username_change, spam_trigger, spam_cleaner, rejected_user or email_bounce
	 * @api-in int $since Only entries from this Unix timestamp. Default the whole log.
	 * @api-in int $until Only entries up to this Unix timestamp. Default now.
	 * @api-in int $user_id Only entries recording this member's action
	 * @api-in int $about_user_id Only entries about this member, where the log records one
	 * @api-in str $content_type Only entries about this content type, where the log records content
	 * @api-in int $content_id Only entries about this content id, where the log records content
	 * @api-in int $limit How many entries to return. Default 50, maximum 200.
	 * @api-in bool $values The user change log only: each change's old_value and new_value, scrubbed and cut to 1000 characters. Never for a protected field.
	 *
	 * @api-out array $entries Newest first
	 * @api-out int $total How many entries match, including any past the limit
	 * @api-out bool $complete False when the limit cut the answer short
	 * @api-out int $scrubbed How many secrets were taken out of the admin log's URLs
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		/** @var LogEntriesRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:LogEntries');

		$logId = $this->filter('log', 'str');
		if (!in_array($logId, $repo->getLogIds(), true))
		{
			return $this->error(\XF::phrase('hampel_adminapi_unknown_log', ['logs' => implode(', ', $repo->getLogIds())]), 400);
		}

		$log = LogEntriesRepository::LOGS[$logId];

		if (!empty($log['super_admin']) && !\XF::visitor()->is_super_admin)
		{
			return $this->noPermission(\XF::phrase('you_must_be_super_admin_to_access_this_page'));
		}

		$filters = [
			'since' => $this->filter('since', 'uint'),
			'until' => $this->filter('until', '?uint'),
			'user_id' => $this->filter('user_id', 'uint') ?: null,
			'about_user_id' => $this->filter('about_user_id', 'uint') ?: null,
			'content_type' => $this->filter('content_type', 'str') ?: null,
			'content_id' => $this->filter('content_id', 'uint') ?: null,
		];
		if ($filters['until'] === null)
		{
			$filters['until'] = \XF::$time;
		}

		$needs = ['user_id' => 'actor', 'about_user_id' => 'subject', 'content_type' => 'content', 'content_id' => 'content'];
		foreach ($needs AS $filter => $part)
		{
			if ($filters[$filter] !== null && !isset($log[$part]))
			{
				return $this->error(\XF::phrase('hampel_adminapi_log_filter_unsupported', ['log' => $logId, 'filter' => $filter]), 400);
			}
		}

		$values = $this->filter('values', 'bool');
		if ($values && !isset($log['values']))
		{
			$withValues = array_keys(array_filter(LogEntriesRepository::LOGS, function (array $declared)
			{
				return isset($declared['values']);
			}));

			return $this->error(\XF::phrase('hampel_adminapi_log_values_unsupported', [
				'log' => $logId,
				'logs' => implode(', ', $withValues),
			]), 400);
		}

		$limit = min($this->filter('limit', 'uint') ?: self::DEFAULT_LIMIT, LogEntriesRepository::MAX_LIMIT);

		$this->logMemberLookup();

		return $this->apiResult([
			'log' => $logId,
			'window' => ['since' => $filters['since'], 'until' => $filters['until']],
		] + $repo->getEntries($logId, $filters, $limit, $values));
	}
}
