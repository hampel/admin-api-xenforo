<?php namespace Tests\Feature;

use Tests\TestCase;

/**
 * The three gates in AbstractController::preDispatchController(), reached by dispatching a route -
 * a controller built directly never runs preDispatch.
 *
 * Every refusal here is a 403, so each test asserts on the message as well. A code-only assertion
 * passes whichever gate fired, and keeps passing with the intended one deleted.
 */
class AuthorisationTest extends TestCase
{
	private const ERROR_LOG = 'admin-api/error-log/';
	private const ADD_ONS = 'admin-api/add-ons/';

	private const SCOPE = 'hampel_admin_api:error_log';

	private const KEY_REFUSED = 'single administrator';
	private const ALL_SCOPES_REFUSED = 'Selected scopes only';
	private const NO_PERMISSION = 'do not have permission';

	protected function tearDown() : void
	{
		\XF::setAccessToken(null);
		\XF::setApiBypassPermissions(false);

		parent::tearDown();
	}

	public function test_an_administrator_with_the_permission_and_the_scope_is_let_in() : void
	{
		$this->actingAsAdminWithKey([self::SCOPE]);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsApiResult($reply);
		$this->assertSame(200, $reply->getResponseCode());
	}

	// gate 1 - the key

	public function test_a_super_user_key_is_refused_even_acting_as_a_super_administrator() : void
	{
		$this->actingAsAdminWithKey(
			[self::SCOPE],
			[],
			['is_super_user' => true],
			['is_super_admin' => true]
		);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::KEY_REFUSED);
	}

	/**
	 * api_bypass_permissions on the request is what makes core's own admin assertions return
	 * early for a super user key. It must buy nothing here.
	 */
	public function test_a_super_user_key_bypassing_permissions_is_still_refused() : void
	{
		$this->actingAsAdminWithKey([self::SCOPE], [], ['is_super_user' => true]);
		\XF::setApiBypassPermissions(true);
		$this->assertTrue(\XF::isApiBypassingPermissions(), 'the bypass this test is about is not active');

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::KEY_REFUSED);
	}

	/**
	 * XF::apiKey() is never null - with no key it is a fallback of type guest.
	 */
	public function test_a_request_with_no_key_is_refused() : void
	{
		$user = $this->actingAsBoardMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($user, ['hampelAdminApi' => true]);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::KEY_REFUSED);
	}

	/**
	 * With a bearer token core makes the token's user the visitor and leaves the key as the
	 * fallback, so an administrator's token would otherwise arrive looking like an administrator.
	 */
	public function test_an_oauth_access_token_is_refused_even_alongside_a_valid_key() : void
	{
		$this->actingAsAdminWithKey([self::SCOPE]);
		\XF::setAccessToken($this->makeEntity('XF:OAuthToken', ['user_id' => self::USER_ID]));

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::KEY_REFUSED);
	}

	// gate 2 - the scope

	public function test_a_key_without_the_scope_is_refused() : void
	{
		$this->actingAsAdminWithKey([], [], ['scopes' => ['user:read' => true]]);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::SCOPE);
	}

	public function test_each_endpoint_asks_for_its_own_scope() : void
	{
		$this->actingAsAdminWithKey(['hampel_admin_api:add_ons']);

		$this->assertReplyIsApiResult($this->dispatch(self::ADD_ONS, 'api'));
		$this->assertReplyIsError($this->dispatch(self::ERROR_LOG, 'api'), 403, self::SCOPE);
	}

	/**
	 * The reason the scope check is not core's: allow_all_scopes satisfies every scope core
	 * checks, so one radio button would hand over every endpoint, member data included. A scope
	 * counts only when it was selected, whoever holds the key.
	 */
	public function test_a_key_allowing_all_scopes_is_refused_even_for_a_super_administrator() : void
	{
		$this->actingAsAdminWithKey([], [], ['allow_all_scopes' => true], ['is_super_admin' => true]);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::ALL_SCOPES_REFUSED);
		$this->assertReplyIsError($reply, 403, self::SCOPE);
	}

	// gate 3 - the administrator's own permission

	public function test_an_administrator_without_the_permission_is_refused() : void
	{
		$this->actingAsAdminHolding(['option', 'addOn'], [self::SCOPE]);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::NO_PERMISSION);
	}

	public function test_a_member_whose_key_has_the_scope_is_refused() : void
	{
		$this->actingAsBoardMember();
		$this->actingAsUserKey(['scopes' => [self::SCOPE => true]]);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::NO_PERMISSION);
	}

	public function test_a_user_key_cannot_use_the_permission_bypass() : void
	{
		$this->actingAsAdminHolding([], [self::SCOPE]);
		\XF::setApiBypassPermissions(true);

		$reply = $this->dispatch(self::ERROR_LOG, 'api');

		$this->assertReplyIsError($reply, 403, self::NO_PERMISSION);
	}

	/**
	 * Admin::hasAdminPermission() answers true for a super administrator whatever is granted, so
	 * for them the key's scopes are the whole of the limit.
	 */
	public function test_a_super_administrator_needs_only_the_scopes() : void
	{
		$this->actingAsAdminHolding([], [self::SCOPE, 'hampel_admin_api:add_ons'], [], ['is_super_admin' => true]);

		$this->assertReplyIsApiResult($this->dispatch(self::ADD_ONS, 'api'));
		$this->assertReplyIsApiResult($this->dispatch(self::ERROR_LOG, 'api'));
		$this->assertReplyIsError($this->dispatch('admin-api/jobs/', 'api'), 403, 'hampel_admin_api:jobs');
	}
}
