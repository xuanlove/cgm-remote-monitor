<?php
declare(strict_types=1);

namespace app\command;

use app\service\HealthRunner;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class HealthCommand extends Command
{
    protected function configure()
    {
        $this->setName('health')
            ->setDescription('Report sync freshness; exits non-zero when data is stale')
            ->addOption('stale', null, Option::VALUE_OPTIONAL, 'Maximum reading age in seconds (default 600)');
    }

    protected function execute(Input $input, Output $output)
    {
        return (new HealthRunner())->run(
            ['stale' => $input->getOption('stale')],
            static function (string $line) use ($output): void {
                $output->writeln($line);
            }
        );
    }
}
