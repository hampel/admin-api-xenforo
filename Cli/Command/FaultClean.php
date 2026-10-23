<?php namespace Hampel\AdminApi\Cli\Command;

use Hampel\AdminApi\Fault\Manager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Never refused. A forum whose config.php no longer enables faults must still be able to remove
 * the ones it has.
 */
class FaultClean extends Command
{
	/**
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('admin-api:fault-clean')
			->setDescription('Removes what admin-api:fault-arm planted, and the errors it raised')
			->addArgument('batch', InputArgument::OPTIONAL, 'The batch to remove. Every batch when omitted');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$manager = new Manager(\XF::app());
		$batch = $input->getArgument('batch');

		if ($batch !== null && !isset($manager->getBatches()[$batch]))
		{
			$output->writeln("<error>There is no batch $batch.</error>");

			return 1;
		}

		$removed = $manager->clean($batch);

		$output->writeln(sprintf(
			'Removed %d page(s), %d member(s), %d job(s), %d cron entr%s and %d add-on record(s).',
			$removed['node'],
			$removed['user'],
			$removed['job'],
			$removed['cron'],
			$removed['cron'] === 1 ? 'y' : 'ies',
			$removed['addon']
		));
		$output->writeln(sprintf(
			'Removed %d error log entr%s and %d failed job record(s) they had raised.',
			$removed['error_log'],
			$removed['error_log'] === 1 ? 'y' : 'ies',
			$removed['failed_job']
		));

		return 0;
	}
}
