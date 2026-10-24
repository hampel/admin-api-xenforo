<?php namespace Tests;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * Four levels up because this add-on id has a vendor in it:
	 * src/addons/<Vendor>/<AddOn> -> the install root. No trailing slash.
	 */
	protected $rootDir = '../../../..';

	/**
	 * Load only this add-on. Booting with every add-on registers each one's vendor tree onto
	 * XF's class loader, and PHPUnit classes can then resolve out of another add-on's copy.
	 */
	protected $addonsToLoad = ['Hampel/AdminApi'];

	protected const USER_ID = 987650;

	/**
	 * Act as an administrator holding the given admin permissions, calling with their own key.
	 *
	 * In a real request XF\Api\App derives the visitor from the key. dispatch() does not go
	 * through that, so the two are set side by side here and kept on the same user_id.
	 *
	 * @param string[] $permissions - admin permission ids held
	 * @param array<string, mixed> $keyValues - overrides for the key's columns
	 * @param array<string, mixed> $adminValues - overrides for the administrator record
	 */
	protected function actingAsAdminWithKey(array $permissions, array $keyValues = [], array $adminValues = []) : \XF\Entity\ApiKey
	{
		$user = $this->actingAsBoardMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($user, array_fill_keys($permissions, true), $adminValues);

		return $this->actingAsUserKey($keyValues);
	}

	/**
	 * A member who can view the board. Core's API controller refuses anyone who cannot, ahead of
	 * this add-on's gates and with the same message the third gate uses - so without the grant a
	 * test of that gate passes on core's refusal and would keep passing with the gate deleted.
	 *
	 * @param array<string, mixed> $values
	 */
	protected function actingAsBoardMember(array $values = []) : \XF\Entity\User
	{
		return $this->actingAsMember(
			$values + ['user_id' => self::USER_ID],
			['general' => ['view' => true]]
		);
	}

	/**
	 * A key bound to the acting user and holding this add-on's scope and no other.
	 *
	 * @param array<string, mixed> $keyValues
	 */
	protected function actingAsUserKey(array $keyValues = []) : \XF\Entity\ApiKey
	{
		return $this->actingAsApiKey($keyValues + [
			'is_super_user' => false,
			'user_id' => self::USER_ID,
			'allow_all_scopes' => false,
			'scopes' => [AbstractController::SCOPE => true],
		]);
	}
}
