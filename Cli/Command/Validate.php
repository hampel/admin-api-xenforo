<?php namespace Hampel\AdminApi\Cli\Command;

use Hampel\AdminApi\Check\HealthCheck;
use Hampel\AdminApi\Cli\RendersReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether this forum can actually do what the add-on is for: the API is on, the scopes, admin
 * permissions and routes are in place, somebody holds a key that can read, and each endpoint's
 * reader runs.
 *
 * The probe logs one error to the server error log, reads it back through the summary and removes
 * it. That is a send - anything watching the error log sees it - and it is the only proof that
 * what the forum logs is what the endpoints read, so it runs by default. --unattended skips it.
 *
 * Exit code: 1 if any check failed, 0 otherwise - a warning does not fail, so a gate can rely on
 * it. --strict makes a warning exit 2, for a monitor that wants to hear of one, and changes
 * nothing else. This is not the monitoring-plugin numbering.
 */
class Validate extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('admin-api:validate')
			->setDescription('Check that the Admin API can work here: the API, scopes, permissions, keys, and each endpoint\'s reader')
			->addOption('unattended', null, InputOption::VALUE_NONE,
				'Skip the probe, which writes an error to the server error log - for a gate nobody is watching')
			->addOption('strict', null, InputOption::VALUE_NONE,
				'Exit 2 when there are warnings and no failures, instead of 0');
	}

	protected function errorLogPrefix(): string
	{
		return 'AdminApi: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;
		$this->checkLabelWidth = 30;

		$checks = $this->getHealthCheck();

		// facts about the forum: these report under --unattended too, so they stay above the probe
		$this->checkSection('Installed');
		$this->rows('REST API', [$checks, 'api']);
		$this->rows('scopes', [$checks, 'scopes']);
		$this->rows('admin permissions', [$checks, 'permissions']);
		$this->rows('routes', [$checks, 'routes']);

		$this->checkSection('Access');
		$this->rows('API keys', [$checks, 'access']);

		$this->checkSection('Endpoints');
		$this->rows('readers', [$checks, 'readers']);

		$this->checkSection('Fault commands');
		$this->rows('fault commands', [$checks, 'faults']);

		$this->checkSection('Probe');
		if ($input->getOption('unattended'))
		{
			$this->checkSkip('probe error', 'skipped by --unattended');
		}
		else
		{
			$prefix = $this->errorLogPrefix();
			$this->rows('probe error', function () use ($checks, $prefix)
			{
				return $checks->probe($prefix);
			});
		}

		$output->writeln('');

		return $this->checkExitCode((bool) $input->getOption('strict'));
	}

	/**
	 * @return HealthCheck
	 */
	protected function getHealthCheck()
	{
		return new HealthCheck(\XF::app());
	}

	/**
	 * Renders what one check returned. Named for what it does: a helper called run() would
	 * collide with the command's own.
	 */
	protected function rows($label, callable $check)
	{
		foreach ((array) $this->probe($label, $check) AS $row)
		{
			[$outcome, $rowLabel, $detail] = $row;

			switch ($outcome)
			{
				case HealthCheck::OK: $this->checkOk($rowLabel, $detail); break;
				case HealthCheck::WARN: $this->checkWarn($rowLabel, $detail); break;
				case HealthCheck::FAIL: $this->checkFail($rowLabel, $detail); break;
				default: $this->checkSkip($rowLabel, $detail);
			}
		}
	}
}
