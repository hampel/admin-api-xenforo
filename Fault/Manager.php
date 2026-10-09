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

	const KINDS = ['page', 'jobs', 'cron', 'add-on'];

	/**
	 * How far in the past a planted job or cron entry is dated: past the hour after which the
	 * endpoints call one stalled or overdue.
	 */
	const OVERDUE_BY = 7200;

	/**
	 * Classes that do not exist, which is the point of them.
	 *
	 * The job's name still has to look like a job's - a \\Job\\ segment - because XenForo checks
	 * the shape of the name before it looks for the class. A name of the wrong shape is not
	 * skipped as a missing job: it throws out of the queue runner, which abandons the rest of
	 * that run and re-queues the job to do the same again a quarter of an hour later.
	 */
	const MISSING_JOB = 'Hampel\\AdminApi\\Job\\NoSuchJob';
	const MISSING_CRON = 'Hampel\\AdminApi\\Cron\\NoSuchCron';

	const ADD_ON_TITLE = 'Admin API fault add-on';

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
	 * Plants one fault of each kind asked for. None of them is code that fails: each is a row that
	 * XenForo itself trips over, the way it would on a forum where something had gone wrong.
	 *
	 * page    a page whose template calls a method on nothing, and members to view it as. Each
	 *         view logs a template error raised by a web request, with that member behind it.
	 * jobs    two queued jobs, both overdue: one whose class no longer exists, as an add-on that
	 *         was removed leaves behind, and one whose data its class rejects. They show as
	 *         stalled until the queue next runs, and then as errors raised by a job.
	 * cron    a cron entry two hours overdue. It shows as overdue until cron next runs.
	 * add-on  a record of an add-on whose upgrade stopped part-way.
	 *
	 * @param string $batch
	 * @param int $memberCount
	 * @param string[] $kinds From KINDS
	 *
	 * @return array batch, and what was planted for each kind - with the members' passwords,
	 *               which exist nowhere else
	 */
	public function arm($batch, $memberCount, array $kinds = self::KINDS)
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

		if (!$kinds || array_diff($kinds, self::KINDS))
		{
			throw new \InvalidArgumentException('A fault is one of: ' . implode(', ', self::KINDS) . '.');
		}

		$armed = ['batch' => $batch];

		if (in_array('page', $kinds, true))
		{
			// the page first: saving a member builds permissions, and whatever builds them
			// remembers the node tree as it was at that moment
			$armed['page'] = $this->createPage($batch);

			$armed['members'] = [];
			for ($i = 1; $i <= $memberCount; $i++)
			{
				$armed['members'][] = $this->createMember($batch, $i);
			}

			$this->rebuildPermissions();
		}

		if (in_array('jobs', $kinds, true))
		{
			$armed['jobs'] = [$this->createJob($batch, 'orphan', self::MISSING_JOB, [])];

			// a core job handed data it rejects. The one used arrived with XenForo 2.3, and a job
			// that fails is only recorded as failed from 2.3 as well, so before it there is
			// neither the job nor anything for it to show
			if (class_exists('XF\\Job\\IconUsage'))
			{
				$armed['jobs'][] = $this->createJob($batch, 'rejected', 'XF\\Job\\IconUsage', ['content_type' => $this->getMarker($batch)]);
			}
		}

		if (in_array('cron', $kinds, true))
		{
			$armed['cron'] = $this->createCronEntry($batch);
		}

		if (in_array('add-on', $kinds, true))
		{
			$armed['add_on'] = $this->createAddOnRecord($batch);
		}

		return $armed;
	}

	/**
	 * A string that appears in every error a batch's jobs raise and in nothing else: it ends
	 * with a colon so that one batch's marker is never the start of another's.
	 *
	 * @param string $batch
	 *
	 * @return string
	 */
	public function getMarker($batch)
	{
		return "adminApiFault:$batch:";
	}

	/**
	 * Written straight to the queue, as an overdue job. Going through the job manager would
	 * bring the forum's next job run forward to now; a job that has been left waiting is the
	 * thing being planted.
	 */
	protected function createJob($batch, $kind, $class, array $data)
	{
		$db = $this->app->db();

		$db->insert('xf_job', [
			'unique_key' => $this->getMarker($batch) . $kind,
			'execute_class' => $class,
			'execute_data' => serialize($data),
			'manual_execute' => 0,
			'trigger_date' => \XF::$time - self::OVERDUE_BY,
		]);
		$jobId = (int) $db->lastInsertId();

		$this->record($batch, 'job', $jobId);

		return ['job_id' => $jobId, 'kind' => $kind, 'execute_class' => $class];
	}

	/**
	 * Written straight to the table for the same reason, and because saving the entity would
	 * refuse a callback that does not exist.
	 */
	protected function createCronEntry($batch)
	{
		$entryId = 'aaf_' . $batch;

		$this->app->db()->insert('xf_cron_entry', [
			'entry_id' => $entryId,
			'cron_class' => self::MISSING_CRON,
			'cron_method' => 'run',
			'run_rules' => json_encode(['day_type' => 'dom', 'dom' => [-1], 'hours' => [-1], 'minutes' => [0]]),
			'active' => 1,
			'next_run' => \XF::$time - self::OVERDUE_BY,
			'addon_id' => 'Hampel/AdminApi',
		]);

		$this->record($batch, 'cron', $entryId);

		return ['entry_id' => $entryId];
	}

	/**
	 * Inactive, and not marked as processing: an add-on that is processing stops XenForo writing
	 * to its error log at all, which would hide every other fault.
	 */
	protected function createAddOnRecord($batch)
	{
		$addOnId = 'AdminApiFault/' . ucfirst($batch);

		$this->app->db()->insert('xf_addon', [
			'addon_id' => $addOnId,
			'title' => self::ADD_ON_TITLE . " ($batch)",
			'version_string' => '1.0.0',
			'version_id' => 1000070,
			'json_hash' => '',
			'active' => 0,
			'is_legacy' => 0,
			'is_processing' => 0,
			'last_pending_action' => 'upgrade:1000170:2',
		]);

		$this->record($batch, 'addon', $addOnId);

		return ['addon_id' => $addOnId, 'pending_action' => 'upgrade'];
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
		$removed = ['node' => 0, 'user' => 0, 'job' => 0, 'cron' => 0, 'addon' => 0, 'error_log' => 0, 'failed_job' => 0];
		$db = $this->app->db();

		foreach (array_reverse($this->getRecords($batch)) AS $record)
		{
			$id = $record['content_id'];

			switch ($record['content_type'])
			{
				case 'node':
					$removed['error_log'] += $this->deleteErrorsFromPage((int) $id);
					$removed['node'] += $this->deleteEntity('XF:Node', (int) $id);
					break;

				case 'user':
					$removed['user'] += $this->deleteEntity('XF:User', (int) $id);
					$this->sweepUser((int) $id);
					break;

				case 'job':
					// by id and by marker both, so a job id reused after the queue ran it is safe
					$removed['job'] += $db->delete(
						'xf_job',
						'job_id = ? AND unique_key LIKE ?',
						[(int) $id, $db->escapeLike($this->getMarker($record['batch']), '?%')]
					);
					$removed['error_log'] += $this->deleteMarked('xf_error_log', 'message', $record['batch']);
					if ($db->getSchemaManager()->tableExists('xf_failed_job'))
					{
						$removed['failed_job'] += $this->deleteMarked('xf_failed_job', 'exception', $record['batch']);
					}
					break;

				case 'cron':
					$removed['cron'] += $db->delete(
						'xf_cron_entry',
						'entry_id = ? AND cron_class = ?',
						[$id, self::MISSING_CRON]
					);
					break;

				case 'addon':
					// the title is checked as well as the id: this must never be a real add-on's row
					$removed['addon'] += $db->delete(
						'xf_addon',
						'addon_id = ? AND title LIKE ? AND active = 0',
						[$id, $db->escapeLike(self::ADD_ON_TITLE, '?%')]
					);
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
	 * What a batch's jobs left behind once the queue ran them: every such row quotes the marker.
	 */
	protected function deleteMarked($table, $column, $batch)
	{
		$db = $this->app->db();

		return $db->delete($table, "$column LIKE ?", $db->escapeLike($this->getMarker($batch), '%?%'));
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
