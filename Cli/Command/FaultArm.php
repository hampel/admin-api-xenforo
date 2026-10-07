<?php namespace Hampel\AdminApi\Cli\Command;

use Hampel\AdminApi\Fault\Manager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FaultArm extends Command
{
	protected function configure()
	{
		$this
			->setName('admin-api:fault-arm')
			->setDescription('Plants a page that raises an error when viewed, and members to view it as. Test forums only.')
			->addOption('batch', null, InputOption::VALUE_REQUIRED, 'A name for this run, 1 to 16 lower case letters and digits')
			->addOption('members', null, InputOption::VALUE_REQUIRED, 'How many members to create', 3);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$manager = new Manager(\XF::app());
		$errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

		if (!$manager->isEnabled())
		{
			$errors->writeln('<error>Refused: faults are not enabled on this forum.</error>');
			$errors->writeln("To enable them, add this to src/config.php - on a test forum only:");
			$errors->writeln("  \$config['hampelAdminApi']['faults'] = true;");

			return 1;
		}

		$batch = $input->getOption('batch') ?: substr(bin2hex(random_bytes(8)), 0, 8);
		$members = max(0, min(50, (int) $input->getOption('members')));

		try
		{
			$armed = $manager->arm($batch, $members);
		}
		catch (\InvalidArgumentException $e)
		{
			$errors->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}
		catch (\Exception $e)
		{
			// whatever was saved before this was recorded as it was saved
			$errors->writeln('<error>Stopped part-way: ' . $e->getMessage() . '</error>');
			$errors->writeln("Remove what was planted with admin-api:fault-clean $batch");

			return 1;
		}

		$errors->writeln("Batch $batch: one page and $members member(s). Remove with admin-api:fault-clean $batch");

		// the passwords exist nowhere else, so this is the only time they can be read
		$output->writeln(json_encode($armed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		return 0;
	}
}
