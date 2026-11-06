<?php namespace Tests\Unit;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Check\HealthCheck;
use Tests\TestCase;

/**
 * The scopes are listed in three places that cannot share a constant: the controller's list, the
 * health check's two groups, and the template modification that groups them on the API key page.
 */
class ScopeListTest extends TestCase
{
	public function test_every_scope_is_counts_values_or_member_data_and_nothing_else() : void
	{
		$grouped = array_merge(HealthCheck::AGGREGATE_SCOPES, [AbstractController::SCOPE_VALUES], array_keys(HealthCheck::DETAIL_SCOPES));

		$this->assertEqualsCanonicalizing(AbstractController::SCOPES, $grouped);
		$this->assertSame(count($grouped), count(array_unique($grouped)));
	}

	public function test_the_key_page_lists_member_data_apart_as_the_health_check_does() : void
	{
		$json = file_get_contents(__DIR__ . '/../../_output/template_modifications/admin/hampel_adminapi_api_key_scopes.json');
		$this->assertIsString($json);
		$modification = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(2, preg_match_all('/in_array\(\$scope\.api_scope_id, \[([^\]]*)\]\)/', $modification['replace'], $lists));
		foreach ($lists[1] AS $list)
		{
			preg_match_all("/'([^']+)'/", $list, $ids);
			$this->assertEqualsCanonicalizing(array_keys(HealthCheck::DETAIL_SCOPES), $ids[1]);
		}
	}
}
