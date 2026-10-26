<?php

namespace App\Console\Commands;

use App\Services\SjdmTestReports;
use Illuminate\Console\Command;
use RuntimeException;

class SeedTestReports extends Command
{
    protected $signature = 'cdrmapp:seed-test-reports';

    protected $description = 'Create 5 synthetic SJDM reports per agency (BFP, PNP and MDRRMO), without notifications or duplicates';

    public function handle(SjdmTestReports $reports): int
    {
        try {
            $result = $reports->create();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info($result['created'].' test reports created; '.$result['existing'].' already existed.');
        $this->info('SJDM test batch: 5 Fire Department (BFP), 5 Crime / Police Department (PNP), 5 MDRRMO.');
        $this->info('All marked as test data. No email or push alerts sent.');

        return self::SUCCESS;
    }
}
