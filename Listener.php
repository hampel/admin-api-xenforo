<?php namespace Hampel\AdminApi;

class Listener
{
	/**
	 * This add-on's own answer to the event it defines: it has no options, and nothing under its
	 * config.php key is a secret. Declaring that is what lets the key's contents be shown.
	 *
	 * @param array $declarations
	 */
	public static function adminApiRedaction(array &$declarations)
	{
		$declarations['Hampel/AdminApi'] = [
			'options' => [],
			'config' => ['hampelAdminApi' => []],
		];
	}
}
