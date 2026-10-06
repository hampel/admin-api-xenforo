<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\ErrorLog as ErrorLogRepository;
use XF\Mvc\ParameterBag;

/**
 * The one endpoint that returns content, not counts. It has its own scope and its own admin
 * permission, and a key holding the read scope gets nothing here.
 *
 * @api-group Admin API
 */
class ErrorEntries extends AbstractController
{
	const DEFAULT_LIMIT = 5;
	const MAX_LIMIT = 20;

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiErrorDetail';
	}

	protected function getScope()
	{
		return self::SCOPE_ERROR_DETAIL;
	}

	/**
	 * Everything here is in the server error log in the admin control panel, so the key's user
	 * must be allowed to read that log there as well. The API is not a way round it.
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		parent::preDispatchController($action, $params);

		$this->assertKeyUserAdminPermission('viewLogs');
	}

	/**
	 * @api-desc Returns one server error log entry in full - message and stack trace - with the
	 *   most recent other entries raised at the same place. The request, user and IP address are
	 *   included only when asked for. Requires a user API key holding the
	 *   hampel_admin_api:error_detail scope, whose user has both the hampelAdminApiErrorDetail and
	 *   the viewLogs admin permissions.
	 *
	 * @api-in int $error_id <req> The entry to return, as given by last_error_id in the error log summary
	 * @api-in int $limit How many entries to return, counting the one asked for. Default 5, maximum 20.
	 * @api-in bool $request Include the recorded request, user id and IP address of each entry
	 *
	 * @api-out int $signature_total How many entries the log holds for this type, file and line
	 * @api-out array $entries The entry asked for, then the others, newest first
	 */
	public function actionGet()
	{
		$errorId = $this->filter('error_id', 'uint');
		if (!$errorId)
		{
			return $this->error(\XF::phrase('hampel_adminapi_error_id_required'), 400);
		}

		$limit = $this->filter('limit', 'uint') ?: self::DEFAULT_LIMIT;
		$limit = min($limit, self::MAX_LIMIT);

		/** @var ErrorLogRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:ErrorLog');

		$result = $repo->getEntries($errorId, $limit, $this->filter('request', 'bool'));
		if ($result === null)
		{
			return $this->notFound(\XF::phrase('requested_log_entry_not_found'));
		}

		return $this->apiResult($result);
	}
}
