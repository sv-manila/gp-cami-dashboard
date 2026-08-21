<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local (dashboard-owned) analytics tables.
 *
 * Everything here lives on the dashboard's own sqlite database, never on the
 * gp-cami hub — the hub connection stays strictly read-only. These tables hold
 * results the hub cannot answer interactively: aggregates that need a full scan
 * of a 13M-row table, and derived values the hub does not store at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Daily counts per stat, so the board can show a trend instead of a
        // point-in-time number. One row per (captured_on, metric, label).
        Schema::create('gp_stat_snapshots', function (Blueprint $t) {
            $t->id();
            $t->date('captured_on');
            $t->string('metric', 40);          // 'table_count', 'record_count_bucket', 'match_state', ...
            $t->string('label', 120);          // 'Identity profiles', '2-5', 'auto_match', ...
            $t->unsignedBigInteger('value');
            $t->boolean('approx')->default(false);
            $t->timestamp('captured_at')->nullable();
            $t->unique(['captured_on', 'metric', 'label']);
            $t->index(['metric', 'captured_on']);
        });

        // Per-account rollup. gp_source_link.account_id has no index on the hub,
        // so grouping by it is a full scan — done once by the snapshot command
        // rather than per page view.
        Schema::create('gp_account_rollups', function (Blueprint $t) {
            $t->unsignedBigInteger('account_id')->primary();
            $t->string('account_name')->nullable();
            $t->unsignedBigInteger('link_count')->default(0);
            $t->unsignedBigInteger('identity_count')->default(0);
            $t->timestamp('captured_at')->nullable();
            $t->index('identity_count');
        });

        // Derived merge basis: why a multi-record identity's members ended up
        // together. gp_source_link.match_key is stamped at first link and never
        // rewritten when a later dedup tier merges, so the hub itself cannot
        // answer this — the dashboard used to re-derive it live on every profile
        // view (and skipped it entirely above 500 links).
        Schema::create('gp_identity_merge_basis', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_id')->primary();
            $t->unsignedInteger('member_count')->default(0);
            $t->json('basis')->nullable();            // label => {value, members}
            $t->json('conflicts')->nullable();        // label => [distinct values]
            $t->boolean('truncated')->default(false); // members sampled, not exhaustive
            $t->timestamp('computed_at')->nullable();
            $t->index('member_count');
        });

        // Over-merge candidates: identities carrying implausibly many source
        // records, kept with the conflicting keys that make them suspicious.
        Schema::create('gp_quality_flags', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('identity_id');
            $t->string('flag', 40);              // 'over_merge', 'dob_conflict', 'npi_conflict'
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->unsignedBigInteger('record_count')->default(0);
            $t->text('detail')->nullable();
            $t->timestamp('captured_at')->nullable();
            $t->unique(['identity_id', 'flag']);
            $t->index(['flag', 'record_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_quality_flags');
        Schema::dropIfExists('gp_identity_merge_basis');
        Schema::dropIfExists('gp_account_rollups');
        Schema::dropIfExists('gp_stat_snapshots');
    }
};
