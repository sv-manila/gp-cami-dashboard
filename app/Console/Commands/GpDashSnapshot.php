<?php

namespace App\Console\Commands;

use App\Models\AccountRollup;
use App\Models\QualityFlag;
use App\Models\StatSnapshot;
use App\Services\MergeBasisDeriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly batch that computes everything the dashboard cannot ask the hub for
 * interactively, and stores the answers on the dashboard's own database.
 *
 * Each aggregate here is a full scan of a 13M-row table — seconds to minutes,
 * fine for a batch, hopeless in a web request. Results land in
 * gp_stat_snapshots / gp_account_rollups / gp_quality_flags.
 *
 *   php artisan gpdash:snapshot
 *   php artisan gpdash:snapshot --only=counts,quality
 */
class GpDashSnapshot extends Command
{
    protected $signature = 'gpdash:snapshot
        {--only= : Comma-separated subset of counts,buckets,links,accounts,quality}
        {--over-merge=25 : record_count above which an identity is flagged as an over-merge candidate}
        {--top=50 : How many over-merge candidates to keep}';

    protected $description = 'Snapshot gp-cami hub aggregates into the dashboard database (trends, accounts, data quality)';

    /** Sections in run order. */
    private const SECTIONS = ['counts', 'buckets', 'links', 'accounts', 'quality'];

    public function handle(MergeBasisDeriver $deriver): int
    {
        $only = $this->option('only')
            ? array_map('trim', explode(',', $this->option('only')))
            : self::SECTIONS;

        $unknown = array_diff($only, self::SECTIONS);
        if ($unknown) {
            $this->error('Unknown section(s): ' . implode(', ', $unknown));
            $this->line('Valid: ' . implode(', ', self::SECTIONS));

            return self::FAILURE;
        }

        $db = DB::connection(config('gpcami.connection'));

        // These are deliberately unbounded — the page-level MAX_EXECUTION_TIME
        // caps exist so a web request fails fast, which is the opposite of what
        // a batch job wants.
        try {
            $db->statement('SET SESSION max_execution_time = 0');
        } catch (\Throwable $e) {
            $this->warn('Could not clear max_execution_time; long scans may be cut short.');
        }

        foreach ($only as $section) {
            $started = microtime(true);
            $this->line("→ {$section}…");
            $this->{'snapshot' . ucfirst($section)}($db, $deriver);
            $this->info(sprintf('  %s done in %.1fs', $section, microtime(true) - $started));
        }

        return self::SUCCESS;
    }

    /** Exact row counts for the stats board tables — today's data point per table. */
    private function snapshotCounts($db): void
    {
        foreach (config('gpcami.stats_tables') as $label => $table) {
            try {
                $n = (int) $db->selectOne("SELECT COUNT(*) c FROM `$table`")->c;
                $this->store(StatSnapshot::TABLE_COUNT, $label, $n);
                $this->line("   {$label}: " . number_format($n));
            } catch (\Throwable $e) {
                $this->warn("   {$label}: unavailable ({$e->getMessage()})");
            }
        }
    }

    /**
     * How many source records each identity carries. A healthy hub is dominated
     * by the '1' bucket; mass in the high buckets is over-merge.
     */
    private function snapshotBuckets($db): void
    {
        $rows = $db->select(
            "SELECT CASE
                      WHEN record_count <= 1    THEN '1'
                      WHEN record_count <= 5    THEN '2-5'
                      WHEN record_count <= 20   THEN '6-20'
                      WHEN record_count <= 100  THEN '21-100'
                      WHEN record_count <= 1000 THEN '101-1000'
                      ELSE '1000+'
                    END AS bucket, COUNT(*) n
               FROM gp_identity_profile
              GROUP BY bucket",
        );
        foreach ($rows as $r) {
            $this->store(StatSnapshot::RECORD_BUCKET, $r->bucket, (int) $r->n);
            $this->line("   {$r->bucket}: " . number_format($r->n));
        }
    }

    /** Link states and match-score bands — the raw material of the review queue. */
    private function snapshotLinks($db): void
    {
        foreach ($db->select('SELECT match_state, COUNT(*) n FROM gp_source_link GROUP BY match_state') as $r) {
            $this->store(StatSnapshot::MATCH_STATE, (string) $r->match_state, (int) $r->n);
            $this->line("   state {$r->match_state}: " . number_format($r->n));
        }

        // Bands mirror the documented thresholds: auto-merge >= 0.92, review
        // band 0.75-0.92, below that a new identity is seeded.
        $rows = $db->select(
            "SELECT CASE
                      WHEN match_score IS NULL   THEN 'unscored'
                      WHEN match_score >= 0.99   THEN '0.99+ (deterministic)'
                      WHEN match_score >= 0.92   THEN '0.92-0.99 (auto)'
                      WHEN match_score >= 0.75   THEN '0.75-0.92 (review band)'
                      ELSE '<0.75'
                    END AS band, COUNT(*) n
               FROM gp_source_link
              GROUP BY band",
        );
        foreach ($rows as $r) {
            $this->store(StatSnapshot::LINK_QUALITY, $r->band, (int) $r->n);
            $this->line("   score {$r->band}: " . number_format($r->n));
        }
    }

    /**
     * Per-account rollup. gp_source_link.account_id is unindexed on the hub, so
     * this is the one place that scan is paid for.
     */
    private function snapshotAccounts($db): void
    {
        $rows = $db->select(
            'SELECT account_id, COUNT(*) link_count, COUNT(DISTINCT identity_id) identity_count
               FROM gp_source_link
              WHERE account_id IS NOT NULL
              GROUP BY account_id',
        );

        $names = $this->accountNames(array_map(fn ($r) => (int) $r->account_id, $rows));
        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            AccountRollup::upsert(
                array_map(fn ($r) => [
                    'account_id'     => (int) $r->account_id,
                    'account_name'   => $names[(int) $r->account_id] ?? null,
                    'link_count'     => (int) $r->link_count,
                    'identity_count' => (int) $r->identity_count,
                    'captured_at'    => $now,
                ], $chunk),
                ['account_id'],
                ['account_name', 'link_count', 'identity_count', 'captured_at'],
            );
        }

        $this->line('   ' . count($rows) . ' accounts rolled up');
    }

    /**
     * Account names come from the CAMI source DB — the hub stores account ids
     * only. Best-effort: a source outage costs names, not the rollup.
     *
     * @param  array<int,int>  $ids
     * @return array<int,string>
     */
    private function accountNames(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        try {
            return DB::connection(config('gpcami.source_connection'))
                ->table('accounts')->whereIn('id', $ids)->pluck('name', 'id')
                ->map(fn ($n) => (string) $n)->all();
        } catch (\Throwable $e) {
            $this->warn('   account names unavailable: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Over-merge candidates: the identities carrying the most source records,
     * each re-checked for members that disagree on a high-precision key. A
     * shared key is why they merged; a conflicting key is why they should not
     * have.
     */
    private function snapshotQuality($db, MergeBasisDeriver $deriver): void
    {
        $threshold = (int) $this->option('over-merge');
        $top = (int) $this->option('top');

        $rows = $db->select(
            'SELECT identity_id, first_name, last_name, record_count
               FROM gp_identity_profile
              WHERE record_count > ?
              ORDER BY record_count DESC
              LIMIT ' . $top,
            [$threshold],
        );

        // Rebuilt wholesale: an identity that has since been split should stop
        // being flagged, and stale rows would misreport the queue depth.
        QualityFlag::query()->delete();

        $now = now();
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $r) {
            $flags = [[
                'identity_id'  => (int) $r->identity_id,
                'flag'         => QualityFlag::OVER_MERGE,
                'first_name'   => $r->first_name,
                'last_name'    => $r->last_name,
                'record_count' => (int) $r->record_count,
                'detail'       => number_format($r->record_count) . ' source records on one identity',
                'captured_at'  => $now,
            ]];

            try {
                $derived = $deriver->derive((int) $r->identity_id);
                foreach (['date of birth' => QualityFlag::DOB_CONFLICT, 'npi' => QualityFlag::NPI_CONFLICT] as $field => $flag) {
                    if (! isset($derived['conflicts'][$field])) {
                        continue;
                    }
                    $c = $derived['conflicts'][$field];
                    $flags[] = [
                        'identity_id'  => (int) $r->identity_id,
                        'flag'         => $flag,
                        'first_name'   => $r->first_name,
                        'last_name'    => $r->last_name,
                        'record_count' => (int) $r->record_count,
                        'detail'       => $c['members'] . ' members disagree: ' . implode(', ', $c['values']),
                        'captured_at'  => $now,
                    ];
                }
            } catch (\Throwable $e) {
                // A blob identity that times out is still worth flagging on
                // size — but say so, rather than reporting a clean run that
                // quietly checked nothing.
                $this->newLine();
                $this->warn("identity {$r->identity_id}: conflict check skipped — " . $e->getMessage());
            }

            QualityFlag::upsert($flags, ['identity_id', 'flag'], ['first_name', 'last_name', 'record_count', 'detail', 'captured_at']);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line('   ' . count($rows) . " identities over {$threshold} records");
    }

    /** One value for one metric today; re-running the command overwrites it. */
    private function store(string $metric, string $label, int $value, bool $approx = false): void
    {
        StatSnapshot::upsert([[
            'captured_on' => now()->toDateString(),
            'metric'      => $metric,
            'label'       => $label,
            'value'       => $value,
            'approx'      => $approx,
            'captured_at' => now(),
        ]], ['captured_on', 'metric', 'label'], ['value', 'approx', 'captured_at']);
    }
}
