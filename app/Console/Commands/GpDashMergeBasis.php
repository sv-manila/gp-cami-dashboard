<?php

namespace App\Console\Commands;

use App\Models\MergeBasis;
use App\Services\MergeBasisDeriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Precompute "why are these records one person?" for multi-record identities.
 *
 * The profile page used to derive this live on every view, and gave up entirely
 * above 500 links — which meant the identities where the question matters most
 * (the over-merged ones) were exactly the ones that never got an answer. Doing
 * it in batch makes the profile page a single indexed read.
 *
 *   php artisan gpdash:merge-basis                  # top 1000 by record_count
 *   php artisan gpdash:merge-basis --identity=3     # one identity, always fresh
 *   php artisan gpdash:merge-basis --limit=5000 --refresh
 */
class GpDashMergeBasis extends Command
{
    protected $signature = 'gpdash:merge-basis
        {--identity= : Compute for one identity id only}
        {--min-records=2 : Only identities carrying at least this many source records}
        {--limit=1000 : How many identities to process, highest record_count first}
        {--refresh : Recompute identities that already have a stored basis}';

    protected $description = 'Derive and store the merge basis (and key conflicts) for multi-record identities';

    public function handle(MergeBasisDeriver $deriver): int
    {
        $db = DB::connection(config('gpcami.connection'));

        try {
            $db->statement('SET SESSION max_execution_time = 0');
        } catch (\Throwable $e) {
            // Non-fatal: the deriver's own query hints still apply.
        }

        $targets = $this->targets($db);
        if (! $targets) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($targets));
        $bar->start();
        $stored = $failed = 0;

        foreach ($targets as $id) {
            try {
                $d = $deriver->derive($id);
                MergeBasis::upsert([[
                    'identity_id'  => $id,
                    'member_count' => $d['member_count'],
                    'basis'        => json_encode($d['basis']),
                    'conflicts'    => json_encode($d['conflicts']),
                    'truncated'    => $d['truncated'],
                    'computed_at'  => now(),
                ]], ['identity_id'], ['member_count', 'basis', 'conflicts', 'truncated', 'computed_at']);
                $stored++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("identity {$id}: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Stored {$stored} identities" . ($failed ? ", {$failed} failed" : '') . '.');

        return $failed && ! $stored ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int,int> */
    private function targets($db): array
    {
        if ($this->option('identity')) {
            return [(int) $this->option('identity')];
        }

        $rows = $db->select(
            'SELECT identity_id
               FROM gp_identity_profile
              WHERE record_count >= ?
              ORDER BY record_count DESC
              LIMIT ' . (int) $this->option('limit'),
            [(int) $this->option('min-records')],
        );
        $ids = array_map(fn ($r) => (int) $r->identity_id, $rows);

        if (! $this->option('refresh')) {
            $done = MergeBasis::whereIn('identity_id', $ids)->pluck('identity_id')->all();
            $ids = array_values(array_diff($ids, $done));
            if ($done) {
                $this->line(count($done) . ' already computed (use --refresh to redo).');
            }
        }

        return $ids;
    }
}
