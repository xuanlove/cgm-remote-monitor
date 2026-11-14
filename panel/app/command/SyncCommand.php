<?php
declare(strict_types=1);

namespace app\command;

use app\service\SyncRunner;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class SyncCommand extends Command
{
    protected function configure()
    {
        $this->setName('sync')
            ->setDescription('Pull Nightscout data into the local mirror')
            ->addOption('user', null, Option::VALUE_OPTIONAL, 'Only sync this username')
            ->addOption('full', null, Option::VALUE_NONE, 'Full backfill instead of the incremental window')
            ->addOption('days', null, Option::VALUE_OPTIONAL, 'Window size in days (default 2, 30 with --full)')
            ->addOption('profiles', null, Option::VALUE_NONE, 'Also refresh the shared profile snapshot');
    }

    protected function execute(Input $input, Output $output)
    {
        $options = [
            'user' => $input->getOption('user'),
            'full' => (bool)$input->getOption('full'),
            'days' => $input->getOption('days'),
            'profiles' => (bool)$input->getOption('profiles'),
        ];

        return (new SyncRunner())->run($options, static function (string $line) use ($output): void {
            $output->writeln($line);
        });
    }
}
