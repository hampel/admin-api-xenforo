<?php namespace Hampel\AdminApi\Fault;

use XF\App;

/**
 * Plants faults for the endpoints to report, and removes them again.
 *
 * Everything planted is recorded as it is saved, so a run that stops half-way leaves a record of
 * exactly what it made and clean() can finish the job. Nothing is identified by a naming
 * convention, and clean() touches nothing it does not have a record for.
 *
 * Planting is refused unless the forum's own config.php asks for it. Cleaning never is: a forum
 * that has had the key taken away must still be able to get rid of what was planted.
 */
class Manager
{
	const TABLE = 'xf_hampel_admin_api_fault';

	/**
	 * The last line calls a method on a variable nothing sets. XenForo only compiles a method call
	 * whose name starts with an allowed prefix such as get, is or can - anything else is refused
	 * when the template is saved, and never reaches a visitor.
	 */
	const PAGE_TEMPLATE = <<<'TEMPLATE'
<xf:title>Admin API fault page</xf:title>

<div class="blockMessage">
	This page exists to raise a template error each time it is viewed. It was created by
	admin-api:fault-arm and is removed by admin-api:fault-clean.
</div>

{{ $hampelAdminApiFault.getFault() }}
TEMPLATE;

	/** @var App */
	protected $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	/**
	 * $config['hampelAdminApi']['faults'] = true; in src/config.php. The switch lives on the forum
	 * and not with whoever runs the command, so it cannot be pointed at the wrong forum.
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		$config = $this->app->config('hampelAdminApi');

		return is_array($config) && !empty($config['faults']);
	}

	/**
	 * @return array batch => [fault_type => count, ..., 'created_date' => int]
	 */
	public function getBatches()
	{
		$batches = [];

		foreach ($this->getRecords() AS $record)
		{
			$batch = $record['batch'];

			if (!isset($batches[$batch]))
			{
				$batches[$batch] = ['created_date' => (int) $record['created_date'], 'records' => []];
			}

			$type = $record['content_type'];
			$batches[$batch]['records'][$type] = ($batches[$batch]['records'][$type] ?? 0) + 1;
			$batches[$batch]['created_date'] = min($batches[$batch]['created_date'], (int) $record['created_date']);
		}

		return $batches;
	}

	/**
	 * A page whose template calls a method on nothing, and members to view it as. Each view logs
	 * a template error raised by a web request, with that member behind it.
	 *
	 * @param string $batch
	 * @param int $memberCount
	 *
	 * @return array batch, page, members - with the passwords, which exist nowhere else
	 */
	public function arm($batch, $memberCount)
	{
		if (!$this->isEnabled())
		{
			throw new \LogicException('Faults are not enabled in this forum\'s config.php.');
		}

		if (!preg_match('/^[a-z0-9]{1,16}$/', $batch))
		{
			throw new \InvalidArgumentException('A batch name is 1 to 16 lower case letters and digits.');
		}

		if (isset($this->getBatches()[$batch]))
		{
			throw new \InvalidArgumentException("Batch $batch already exists. Clean it first, or name another.");
		}

		// the page first: saving a member builds permissions, and whatever builds them remembers
		// the node tree as it was at that moment
		$page = $this->createPage($batch);

		$members = [];
		for ($i = 1; $i <= $memberCount; $i++)
		{
			$members[] = $this->createMember($batch, $i);
		}

		$this->rebuildPermissions();

		return ['batch' => $batch, 'page' => $page, 'members' => $members];
	}

	/**
	 * A new node cannot be viewed by anyone until the permission cache has an entry for it.
	 * XenForo queues that rebuild for an administrator to trigger, and it reads the node tree,
	 * whose own rebuild is deferred to the end of the request - so the tree is settled first, a
	 * builder that already read the old tree is discarded, and the rebuild is then run until it
	 * says it has finished, since it works in slices.
	 */
	protected function rebuildPermissions()
	{
		\XF::triggerRunOnce();

		// a builder made earlier in this process holds the tree from before the node existed
		$this->app->container()->decache('permission.builder');

		$jobs = $this->app->jobManager();
		$jobs->enqueueUnique('permissionRebuild', 'XF:PermissionRebuild', [], false);

		for ($i = 0; $i < 100 && $jobs->getUniqueJob('permissionRebuild'); $i++)
		{
			$jobs->runUnique('permissionRebuild', 30);
		}
	}

	protected function createMember($batch, $number)
	{
		$password = bin2hex(random_bytes(12));

		/** @var \XF\Entity\User $user */
		$user = $this->app->repository('XF:User')->setupBaseUser();
		$user->username = "fault_{$batch}_{$number}";
		$user->email = "fault-{$batch}-{$number}@example.invalid";
		$user->user_state = 'valid';
		$user->getRelationOrDefault('Auth')->setPassword($password);
		$user->save();

		$this->record($batch, 'user', $user->user_id);

		return ['user_id' => $user->user_id, 'username' => $user->username, 'password' => $password];
	}

	protected function createPage($batch)
	{
		/** @var \XF\Entity\Node $node */
		$node = $this->app->em()->create('XF:Node');
		$node->node_type_id = 'Page';
		$node->bulkSet([
			'title' => "Admin API fault page ($batch)",
			'node_name' => "admin-api-fault-$batch",
			'description' => '',
			'parent_node_id' => 0,
			'display_order' => 100000,
			'display_in_list' => false,
		]);

		/** @var \XF\Entity\Page $page */
		$page = $node->getDataRelationOrDefault(false);
		$page->modified_date = \XF::$time;
		$node->addCascadedSave($page);
		$node->save();

		// recorded before the template, so a failure there still leaves the node findable
		$this->record($batch, 'node', $node->node_id);

		$template = $page->getMasterTemplate();
		$template->template = self::PAGE_TEMPLATE;
		$template->save();

		return [
			'node_id' => $node->node_id,
			'route' => 'pages/' . $node->node_name . '/',
			'template' => 'public:' . $page->getTemplateName(),
		];
	}

	/**
	 * Removes what was recorded, newest first, and each record only once its content has gone.
	 *
	 * @param string|null $batch Every batch when null
	 *
	 * @return array content type => how many were removed, plus 'error_log'
	 */
	public function clean($batch = null)
	{
		$removed = ['node' => 0, 'user' => 0, 'error_log' => 0];

		foreach (array_reverse($this->getRecords($batch)) AS $record)
		{
			$id = (int) $record['content_id'];

			switch ($record['content_type'])
			{
				case 'node':
					$removed['error_log'] += $this->deleteErrorsFromPage($id);
					$removed['node'] += $this->deleteEntity('XF:Node', $id);
					break;

				case 'user':
					$removed['user'] += $this->deleteEntity('XF:User', $id);
					$this->sweepUser($id);
					break;
			}

			$this->app->db()->delete(self::TABLE, 'fault_id = ?', $record['fault_id']);
		}

		return $removed;
	}

	protected function deleteEntity($shortName, $id)
	{
		$entity = $this->app->em()->find($shortName, $id);
		if (!$entity)
		{
			return 0;
		}

		$entity->delete();

		return 1;
	}

	/**
	 * The errors a fault page raised are part of the fault. They are found by the path of the
	 * page's own compiled template in their trace, which nothing else has.
	 */
	protected function deleteErrorsFromPage($nodeId)
	{
		return $this->app->db()->delete(
			'xf_error_log',
			'trace_string LIKE ?',
			'%/public/_page_node.' . $nodeId . '.php(%'
		);
	}

	/**
	 * Rows XenForo writes about a member as they browse, which deleting the member leaves behind.
	 */
	protected function sweepUser($userId)
	{
		$db = $this->app->db();

		$db->delete('xf_ip', 'user_id = ?', $userId);
		$db->delete('xf_session_activity', 'user_id = ?', $userId);
		$db->delete('xf_user_alert', 'alerted_user_id = ? OR user_id = ?', [$userId, $userId]);
	}

	protected function record($batch, $contentType, $contentId)
	{
		$this->app->db()->insert(self::TABLE, [
			'batch' => $batch,
			'content_type' => $contentType,
			'content_id' => $contentId,
			'created_date' => \XF::$time,
		]);
	}

	/**
	 * @param string|null $batch
	 *
	 * @return array Oldest first
	 */
	protected function getRecords($batch = null)
	{
		$db = $this->app->db();

		if ($batch === null)
		{
			return $db->fetchAll('SELECT * FROM ' . self::TABLE . ' ORDER BY fault_id');
		}

		return $db->fetchAll('SELECT * FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY fault_id', $batch);
	}
}
