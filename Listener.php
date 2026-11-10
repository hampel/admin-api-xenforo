<?php namespace Hampel\AdminApi;

class Listener
{
	/**
	 * This add-on's own answer to the event it defines: it has no options, and nothing under its
	 * config.php key is a secret. Declaring that is what lets the key's contents be shown.
	 *
	 * @param array<string, array<string, mixed>> $declarations Each add-on's declaration, by its id
	 *
	 * @return void
	 */
	public static function adminApiRedaction(array &$declarations)
	{
		$declarations['Hampel/AdminApi'] = [
			'options' => [],
			'config' => ['hampelAdminApi' => []],
		];
	}

	/**
	 * This add-on's own answer to the validate event: the checks admin-api:validate runs, in its
	 * order. The probe writes an error to the server error log, which a request must not, so it
	 * never runs over the API, as --unattended skips it on the command line.
	 *
	 * @param array<string, array<string, mixed>> $validators Each add-on's declaration, by its id
	 *
	 * @return void
	 */
	public static function adminApiValidate(array &$validators)
	{
		$validators['Hampel/AdminApi'] = [
			'class' => \Hampel\AdminApi\Check\HealthCheck::class,
			'checks' => [
				'api' => ['label' => 'REST API', 'method' => 'api', 'api' => true],
				'scopes' => ['label' => 'Scopes', 'method' => 'scopes', 'api' => true],
				'permissions' => ['label' => 'Admin permissions', 'method' => 'permissions', 'api' => true],
				'routes' => ['label' => 'Routes', 'method' => 'routes', 'api' => true],
				'access' => ['label' => 'API keys', 'method' => 'access', 'api' => true],
				'readers' => ['label' => 'Endpoint readers', 'method' => 'readers', 'api' => true],
				'faults' => ['label' => 'Fault commands', 'method' => 'faults', 'api' => true],
				'probe' => ['label' => 'Probe error', 'method' => 'probe'],
			],
		];
	}
}
