<?php namespace Hampel\AdminApi\Cli\Command;

use Hampel\AdminApi\Fault\Manager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FaultList extends Command
{
	protected function configure()
	{
		$this
			->setName('admin-api:fault-list')
			->setDescription('Lists what admin-api:fault-arm has planted and whether it is allowed to plant more');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$manager = new Manager(\XF::app());

		$output->writeln('Faults are ' . ($manager->isEnabled() ? 'enabled' : 'not enabled') . ' in this forum\'s config.php.');

		$batches = $manager->getBatches();
		if (!$batches)
		{
			$output->writeln('Nothing is planted.');

			return 0;
		}

		foreach ($batches AS $batch => $details)
		{
			$parts = [];
			foreach ($details['records'] AS $type => $count)
			{
				$parts[] = "$count $type";
			}

			$output->writeln(sprintf('%-16s %s  %s', $batch, gmdate('Y-m-d H:i', $details['created_date']) . ' UTC', implode(', ', $parts)));
		}

		return 0;
	}
}
