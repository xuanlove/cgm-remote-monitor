<?php
declare(strict_types=1);

namespace app\command;

use app\service\CleanupRunner;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class CleanupCommand extends Command
{
    protected function configure()
    {
        $this->setName('cleanup')
            ->setDescription('Apply the mirror retention policy')
            ->addOption('days', null, Option::VALUE_OPTIONAL, 'Keep glucose readings for N days (default 120)')
            ->addOption('treatments-days', null, Option::VALUE_OPTIONAL, 'Keep treatments for N days (default 365)')
            ->addOption('logs-days', null, Option::VALUE_OPTIONAL, 'Keep sync logs for N days (default 7)')
            ->addOption('alerts-days', null, Option::VALUE_OPTIONAL, 'Keep alert events for N days (default 30)')
            ->addOption('vacuum', null, Option::VALUE_NONE, 'Run VACUUM afterwards');
    }

    protected function execute(Input $input, Output $output)
    {
        $options = [
            'days' => $input->getOption('days'),
            'treatments-days' => $input->getOption('treatments-days'),
            'logs-days' => $input->getOption('logs-days'),
            'alerts-days' => $input->getOption('alerts-days'),
            'vacuum' => (bool)$input->getOption('vacuum'),
        ];

        return (new CleanupRunner())->run($options, static function (string $line) use ($output): void {
            $output->writeln($line);
        });
    }
}
