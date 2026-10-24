<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * User upgrades: what is on offer, who holds what, and what was paid.
 *
 * A payment is read as that it was made, when, through which provider, and at what price - the
 * price is the purchase's own, from the purchase request, not what the provider reported. The
 * provider's callback in `xf_payment_provider_log.log_details` is never read: XenForo holds no card
 * numbers, but that column can carry the payer's name and address. Of an upgrade record's `extra`,
 * only the price and length XenForo itself writes there are read.
 */
class UserUpgrade extends Repository
{
	const MAX_DAYS = 366;

	/** The most payments returned for one record, newest first */
	const MAX_PAYMENTS = 20;

	/**
	 * Every upgrade with how many hold it, how many end in the next $days days and how many ended
	 * in the last $days; expiries by day on both sides; and payments and takings in the last $days.
	 *
	 * @param int $now
	 * @param int $days
	 *
	 * @return array<string, mixed>
	 */
	public function getSummary($now, $days)
	{
		$db = $this->db();
		$ahead = $now + $days * 86400;
		$behind = $now - $days * 86400;
		$cancelled = $this->hasCancelled();

		$active = $db->fetchAllKeyed('
			SELECT user_upgrade_id,
				COUNT(*) AS active,
				SUM(end_date = 0) AS permanent,
				SUM(end_date > 0 AND end_date <= ?) AS ending
				' . ($cancelled ? ', SUM(is_cancelled) AS cancelled' : '') . '
			FROM xf_user_upgrade_active
			GROUP BY user_upgrade_id
		', 'user_upgrade_id', $ahead);

		$expired = $db->fetchPairs('
			SELECT user_upgrade_id, COUNT(*)
			FROM xf_user_upgrade_expired
			WHERE end_date >= ? AND end_date <= ?
			GROUP BY user_upgrade_id
		', [$behind, $now]);

		$upgrades = [];
		foreach ($db->fetchAll('SELECT * FROM xf_user_upgrade ORDER BY display_order, user_upgrade_id') AS $row)
		{
			$id = (int) $row['user_upgrade_id'];
			$counts = $active[$id] ?? [];

			$upgrades[] = [
				'user_upgrade_id' => $id,
				'title' => $row['title'],
				'can_purchase' => (bool) $row['can_purchase'],
				'recurring' => (bool) $row['recurring'],
				'cost_amount' => $row['cost_amount'],
				'cost_currency' => $row['cost_currency'],
				'length_amount' => (int) $row['length_amount'],
				'length_unit' => $row['length_unit'] ?: null,
				'active' => (int) ($counts['active'] ?? 0),
				'permanent' => (int) ($counts['permanent'] ?? 0),
				// XenForo 2.2 does not record a cancelled subscription
				'cancelled' => $cancelled ? (int) ($counts['cancelled'] ?? 0) : null,
				'ending' => (int) ($counts['ending'] ?? 0),
				'expired' => (int) ($expired[$id] ?? 0),
			];
		}

		return [
			'days' => $days,
			'active' => array_sum(array_column($upgrades, 'active')),
			'ending' => array_sum(array_column($upgrades, 'ending')),
			'expired' => array_sum(array_column($upgrades, 'expired')),
			'upgrades' => $upgrades,
			// a permanent upgrade's end date is 0, so it falls outside a window that starts now
			'ending_by_day' => $this->getDays('xf_user_upgrade_active', $now, $ahead),
			'expired_by_day' => $this->getDays('xf_user_upgrade_expired', $behind, $now),
			'payments' => $this->getPaymentSummary($behind, $now),
		];
	}

	/**
	 * Upgrade records with their members, the price and how it was paid.
	 *
	 * @param string $state active or expired
	 * @param int $now
	 * @param int $days Active: ending within this many days, 0 for any. Expired: ended within it.
	 * @param int|null $upgradeId
	 * @param int|null $userId
	 * @param int $limit
	 *
	 * @return array{total: int, complete: bool, records: list<array<string, mixed>>}
	 */
	public function getRecords($state, $now, $days, $upgradeId, $userId, $limit)
	{
		$db = $this->db();
		$isActive = $state === 'active';
		$table = $isActive ? 'xf_user_upgrade_active' : 'xf_user_upgrade_expired';

		$where = [];
		$params = [];
		if ($upgradeId)
		{
			$where[] = 'record.user_upgrade_id = ?';
			$params[] = $upgradeId;
		}
		if ($userId)
		{
			$where[] = 'record.user_id = ?';
			$params[] = $userId;
		}
		if ($days && $isActive)
		{
			$where[] = 'record.end_date > 0 AND record.end_date <= ?';
			$params[] = $now + $days * 86400;
		}
		else if ($days)
		{
			$where[] = 'record.end_date >= ?';
			$params[] = $now - $days * 86400;
		}
		$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		$total = (int) $db->fetchOne("SELECT COUNT(*) FROM $table AS record $whereSql", $params);

		// soonest to end first, a permanent one last; the most recently ended first
		$order = $isActive ? 'record.end_date = 0, record.end_date, record.user_upgrade_record_id' : 'record.end_date DESC, record.user_upgrade_record_id DESC';
		$extraColumns = $isActive
			? ($this->hasCancelled() ? ', record.is_cancelled' : '')
			: ', record.original_end_date';

		$rows = $db->fetchAll("
			SELECT record.user_upgrade_record_id, record.user_id, record.user_upgrade_id, record.extra,
				record.start_date, record.end_date, record.purchase_request_key $extraColumns,
				user.username, upgrade.title,
				request.provider_id, request.payment_profile_id
			FROM $table AS record
			LEFT JOIN xf_user AS user ON (user.user_id = record.user_id)
			LEFT JOIN xf_user_upgrade AS upgrade ON (upgrade.user_upgrade_id = record.user_upgrade_id)
			LEFT JOIN xf_purchase_request AS request ON (request.request_key = record.purchase_request_key)
			$whereSql
			ORDER BY $order
			LIMIT " . (int) $limit . '
		', $params);

		$payments = $this->getPayments(array_filter(array_column($rows, 'purchase_request_key')));

		$records = [];
		foreach ($rows AS $row)
		{
			$price = $this->readPrice($row['extra']);
			$key = (string) $row['purchase_request_key'];

			$record = [
				'user_upgrade_record_id' => (int) $row['user_upgrade_record_id'],
				'user_id' => (int) $row['user_id'],
				'username' => $row['username'],
				'user_upgrade_id' => (int) $row['user_upgrade_id'],
				'title' => $row['title'],
				'start_date' => (int) $row['start_date'],
				'end_date' => $row['end_date'] ? (int) $row['end_date'] : null,
			];
			if ($isActive)
			{
				$record['is_cancelled'] = array_key_exists('is_cancelled', $row) ? (bool) $row['is_cancelled'] : null;
			}
			else
			{
				$record['original_end_date'] = $row['original_end_date'] ? (int) $row['original_end_date'] : null;
			}

			$records[] = $record + $price + [
				// no purchase request: given in the control panel, not bought
				'provider' => $row['provider_id'] !== null && $row['provider_id'] !== '' ? $row['provider_id'] : null,
				'payment_profile_id' => $row['payment_profile_id'] !== null ? (int) $row['payment_profile_id'] : null,
				'payments' => $key !== '' ? ($payments[$key] ?? []) : [],
			];
		}

		return ['total' => $total, 'complete' => $total <= count($records), 'records' => $records];
	}

	/**
	 * The price and length XenForo writes into a record's extra when the upgrade is given. Whatever
	 * else a payment provider passed in beside them is not read.
	 *
	 * @param mixed $extra The stored JSON
	 *
	 * @return array{cost_amount: string|null, cost_currency: string|null, length_amount: int|null, length_unit: string|null}
	 */
	protected function readPrice($extra)
	{
		$extra = is_string($extra) ? json_decode($extra, true) : null;
		$extra = is_array($extra) ? $extra : [];

		$amount = $extra['cost_amount'] ?? null;
		$currency = $extra['cost_currency'] ?? null;
		$unit = $extra['length_unit'] ?? null;

		return [
			'cost_amount' => is_numeric($amount) ? (string) $amount : null,
			'cost_currency' => is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : null,
			'length_amount' => is_numeric($extra['length_amount'] ?? null) ? (int) $extra['length_amount'] : null,
			'length_unit' => in_array($unit, ['day', 'month', 'year'], true) ? $unit : null,
		];
	}

	/**
	 * Each purchase's payments, newest first: when, and the provider's own transaction id.
	 *
	 * @param string[] $keys Purchase request keys
	 *
	 * @return array<string, list<array{date: int, transaction_id: string|null}>>
	 */
	protected function getPayments(array $keys)
	{
		if (!$keys)
		{
			return [];
		}

		$rows = $this->db()->fetchAll("
			SELECT purchase_request_key, log_date, transaction_id
			FROM xf_payment_provider_log
			WHERE purchase_request_key IN (" . $this->db()->quote(array_values(array_unique($keys))) . ")
				AND log_type = 'payment'
			ORDER BY log_date DESC, provider_log_id DESC
		");

		$payments = [];
		foreach ($rows AS $row)
		{
			$key = (string) $row['purchase_request_key'];
			if (count($payments[$key] ?? []) >= self::MAX_PAYMENTS)
			{
				continue;
			}

			$payments[$key][] = [
				'date' => (int) $row['log_date'],
				'transaction_id' => $row['transaction_id'] !== '' ? $row['transaction_id'] : null,
			];
		}

		return $payments;
	}

	/**
	 * Upgrade payments in the window, by provider and by currency. The amount is each purchase's
	 * price, once for every payment logged against it.
	 *
	 * @param int $since
	 * @param int $until
	 *
	 * @return array{total: int, by_provider: list<array<string, mixed>>, by_currency: list<array<string, mixed>>}
	 */
	protected function getPaymentSummary($since, $until)
	{
		$rows = $this->db()->fetchAll("
			SELECT request.provider_id, request.cost_currency, COUNT(*) AS payments,
				SUM(request.cost_amount) AS amount, MAX(log.log_date) AS newest_date
			FROM xf_payment_provider_log AS log
			INNER JOIN xf_purchase_request AS request ON (request.request_key = log.purchase_request_key)
			WHERE log.log_type = 'payment'
				AND log.log_date >= ? AND log.log_date <= ?
				AND request.purchasable_type_id = 'user_upgrade'
			GROUP BY request.provider_id, request.cost_currency
		", [$since, $until]);

		$providers = array_fill_keys($this->db()->fetchAllColumn('SELECT provider_id FROM xf_payment_provider'), true);

		$byProvider = [];
		$byCurrency = [];
		foreach ($rows AS $row)
		{
			// a provider the forum does not have is counted, unnamed
			$provider = isset($providers[$row['provider_id']]) ? $row['provider_id'] : null;
			$currency = preg_match('/^[A-Za-z]{3}$/', (string) $row['cost_currency']) ? strtoupper($row['cost_currency']) : null;
			$count = (int) $row['payments'];
			$newest = (int) $row['newest_date'];

			$p = $provider ?? '';
			$byProvider[$p] = $byProvider[$p] ?? ['provider' => $provider, 'payments' => 0, 'newest_date' => 0];
			$byProvider[$p]['payments'] += $count;
			$byProvider[$p]['newest_date'] = max($byProvider[$p]['newest_date'], $newest);

			$c = $currency ?? '';
			$byCurrency[$c] = $byCurrency[$c] ?? ['currency' => $currency, 'payments' => 0, 'amount' => '0.00'];
			$byCurrency[$c]['payments'] += $count;
			$byCurrency[$c]['amount'] = number_format((float) $byCurrency[$c]['amount'] + (float) $row['amount'], 2, '.', '');
		}

		return [
			'total' => array_sum(array_column($byProvider, 'payments')),
			'by_provider' => array_values($byProvider),
			'by_currency' => array_values($byCurrency),
		];
	}

	/**
	 * Records ending on each UTC day between two times, quiet days included.
	 *
	 * @param string $table
	 * @param int $from
	 * @param int $to
	 *
	 * @return list<array{day: string, count: int}>
	 */
	protected function getDays($table, $from, $to)
	{
		$counts = $this->db()->fetchPairs("
			SELECT FLOOR(end_date / 86400) AS day_number, COUNT(*)
			FROM $table
			WHERE end_date >= ? AND end_date <= ?
			GROUP BY day_number
		", [$from, $to]);

		$days = [];
		for ($day = (int) floor($from / 86400); $day <= (int) floor($to / 86400); $day++)
		{
			$days[] = ['day' => gmdate('Y-m-d', $day * 86400), 'count' => (int) ($counts[$day] ?? 0)];
		}

		return $days;
	}

	/**
	 * XenForo 2.3 records a subscription cancelled at the provider; 2.2 does not.
	 *
	 * @return bool
	 */
	protected function hasCancelled()
	{
		return isset($this->em->getEntityStructure('XF:UserUpgradeActive')->columns['is_cancelled']);
	}
}
