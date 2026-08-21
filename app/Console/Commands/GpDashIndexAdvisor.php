<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports the hub indexes the dashboard's newer pages want, and can create them.
 *
 * The dashboard is read-only against gp-cami on purpose, so this never runs as
 * part of a page or of the nightly snapshot — creating an index is a schema
 * change to someone else's database and stays an explicit, confirmed action.
 * Without them the affected pages still work; they fall back to a capped query
 * and say so.
 *
 *   php artisan gpdash:index-advisor            # report only
 *   php artisan gpdash:index-advisor --apply    # create the missing ones
 */
class GpDashIndexAdvisor extends Command
{
    protected $signature = 'gpdash:index-advisor {--apply : Create the missing indexes on the hub}';

    protected $description = 'Report (and optionally create) the gp-cami hub indexes the dashboard pages need';

    /** @var array<int,array{table:string,name:string,columns:string,why:string}> */
    private const WANTED = [
        [
            'table' => 'gp_source_link', 'name' => 'idx_account', 'columns' => 'account_id',
            'why' => 'Account lens — listing the identities in one account is a full scan without it.',
        ],
        [
            'table' => 'gp_source_link', 'name' => 'idx_state_score', 'columns' => 'match_state, match_score',
            'why' => 'Review queue — orders the flagged links by weakest score without a filesort.',
        ],
        [
            'table' => 'gp_identity_credential', 'name' => 'idx_credential_match', 'columns' => 'credential_match_id',
            'why' => 'Match-payload gate — currently an index skip scan over the composite PRIMARY.',
        ],
        [
            'table' => 'gp_identity_exclusion', 'name' => 'idx_match', 'columns' => 'match_id',
            'why' => 'Match-payload gate for exclusions — same skip scan as above.',
        ],
        [
            'table' => 'gp_identity_profile', 'name' => 'idx_record_count', 'columns' => 'record_count',
            'why' => 'Over-merge tail — turns the nightly ORDER BY record_count DESC scan into a range read.',
        ],
        [
            'table' => 'gp_identity_profile', 'name' => 'idx_last_updated', 'columns' => 'last_updated',
            'why' => 'Recently-updated ordering and keyset pagination over search results.',
        ],
    ];

    public function handle(): int
    {
        $db = DB::connection(config('gpcami.connection'));

        $missing = [];
        foreach (self::WANTED as $want) {
            if ($this->exists($db, $want['table'], $want['name'])) {
                $this->line("  <fg=green>✓</> {$want['table']}.{$want['name']}");
                continue;
            }
            $missing[] = $want;
            $this->line("  <fg=yellow>·</> {$want['table']}.{$want['name']} <fg=gray>({$want['columns']})</>");
            $this->line("      {$want['why']}");
        }

        if (! $missing) {
            $this->newLine();
            $this->info('All advised indexes are present.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('DDL for the missing indexes:');
        foreach ($missing as $m) {
            $this->line("  CREATE INDEX {$m['name']} ON {$m['table']} ({$m['columns']});");
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Report only. Re-run with --apply to create them.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This writes to the gp-cami hub — the dashboard is otherwise read-only.');
        $this->warn('Building an index on a 13M-row table locks nothing but does take minutes and disk.');
        if (! $this->confirm('Create ' . count($missing) . ' index(es) on ' . config('gpcami.connection') . '?', false)) {
            $this->line('Aborted; nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($missing as $m) {
            $this->line("Creating {$m['name']} on {$m['table']}…");
            $started = microtime(true);
            try {
                $db->statement("CREATE INDEX `{$m['name']}` ON `{$m['table']}` ({$this->quote($m['columns'])})");
                $this->info(sprintf('  done in %.1fs', microtime(true) - $started));
            } catch (\Throwable $e) {
                $this->error('  failed: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function exists($db, string $table, string $index): bool
    {
        return (int) $db->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index],
        )->c > 0;
    }

    /** "a, b" => "`a`, `b`" — the column list is a constant above, never user input. */
    private function quote(string $columns): string
    {
        return implode(', ', array_map(
            fn ($c) => '`' . trim($c) . '`',
            explode(',', $columns),
        ));
    }
}
