<?php

declare(strict_types=1);

namespace App\Command;

use Fixwire\MonitorConfig;
use Fixwire\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The nightly reports, run by cron (0 3 * * *): check-ins to the nightly-report monitor around
 * each run, so Fixwire also notices a night it doesn't run.
 */
#[AsCommand('app:send-reports', 'Send the nightly reports')]
final class SendReportsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schedule = MonitorConfig::crontab('0 3 * * *', checkInMargin: 10, timezone: 'Europe/Berlin');

        return \Fixwire\withMonitor('nightly-report', $schedule, function () use ($output): int {
            $failed = 0;
            foreach (['acme' => [1200, 800], 'globex' => [], 'initech' => [4300]] as $account => $invoices) {
                try {
                    if ($invoices === []) { // globex has no invoices, which the report can't handle
                        throw new \RuntimeException("building the report for {$account}", 0, new \LengthException('no invoices'));
                    }
                    $output->writeln(\sprintf('%s: %.2f EUR', $account, array_sum($invoices) / 100));
                } catch (\RuntimeException $e) {
                    $failed++;
                    \Fixwire\withScope(static function (Scope $scope) use ($account, $e): void {
                        $scope->setTag('account', $account);
                        \Fixwire\captureException($e); // and carry on with the next account
                    });
                }
            }
            if ($failed > 0) {
                // A failed run: the check-in says error (withMonitor sees the exception).
                throw new \RuntimeException("{$failed} report(s) failed");
            }

            return Command::SUCCESS;
        });
    }
}
