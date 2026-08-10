<?php

/*
|--------------------------------------------------------------------------
| gp-cami (golden_profile) integration
|--------------------------------------------------------------------------
| Central place mapping this dashboard to the gp-cami golden_profile hub.
| The dashboard reads gp_identity_profile as the master "person" record
| (denormalized: name, dob, plus JSON rollups of addresses, licenses,
| credentials, exclusions, accounts, source records).
*/

return [

    'connection' => 'golden_profile',

    // CAMI source DB — holds the raw match payloads the hub does not copy.
    'source_connection' => 'src',

    // Master person/profile table used for search + profile display.
    'profile_table' => 'gp_identity_profile',

    // The source system and table a bare employee id belongs to. Both leading
    // columns of gp_source_link.uq_source, so pinning them keeps an employee-id
    // lookup a point read instead of an index skip scan.
    'default_system_id' => env('GPCAMI_SYSTEM_ID', 1),
    'source_table' => 'employees',

    'columns' => [
        'id'         => 'identity_id',
        'uuid'       => 'identity_uuid',
        'first_name' => 'first_name',
        'last_name'  => 'last_name',
        'middle'     => 'middle_name',
        'dob'        => 'date_of_birth',
    ],

    // Tables counted on the stats board (label => table).
    'stats_tables' => [
        'Identity profiles'   => 'gp_identity_profile',
        'Identities'          => 'gp_identity',
        'Staged persons'      => 'stg_person',
        'Licenses'            => 'gp_license',
        'Credential links'    => 'gp_identity_credential',
        'Exclusion links'     => 'gp_identity_exclusion',
    ],

    // One-line explanation per stat (label => help text), shown on hover.
    'stats_help' => [
        'Identity profiles' => 'Denormalized read model — one wide, ready-to-serve row per resolved person (what this dashboard and the API read).',
        'Identities'        => 'Distinct real people after resolution. Many source records collapse into one identity.',
        'Staged persons'    => 'Source employee rows mapped into the canonical staging shape before resolution. Roughly one per source record.',
        'Licenses'          => 'Professional licenses (number + state + board) attached to identities, deduplicated across sources.',
        'Credential links'  => 'Credential-verification matches rolled up to an identity (confirmed links) — a person’s registry credential status.',
        'Exclusion links'   => 'Exclusion-list hits rolled up to an identity (candidate links) — potential OIG/SAM/state exclusions to review.',
    ],

    /*
     | Narrow column set for every list view (search results, review queue,
     | account lens, exports).
     |
     | Never `SELECT *` for a list: a single gp_identity_profile row can carry
     | >100MB of JSON rollups (identity 3 alone has a 69MB credentials blob), so
     | a 500-row page of `*` is not a slow query, it is an out-of-memory crash.
     | The rollups are loaded only on the one-identity profile view.
     */
    'list_columns' => [
        'identity_id', 'identity_uuid', 'first_name', 'middle_name', 'last_name', 'suffix',
        'date_of_birth', 'ssn_last_four', 'npi', 'dea_number', 'city', 'state',
        'record_count', 'account_count', 'license_count', 'credential_count',
        'exclusion_count', 'has_active_exclusion', 'has_active_board_action',
        'confidence', 'last_updated',
    ],

    // Rows per page in the search results list.
    'per_page' => 50,

    // Hard cap on a CSV/JSON export, so a two-letter prefix search can't stream
    // the whole hub to a browser.
    'export_limit' => 10000,

    // gp-cami API base for the docs-page playground (the hub app, not this one).
    'api_base' => env('GPCAMI_API_BASE', 'http://127.0.0.1:8000'),

    // JSON rollup columns on gp_identity_profile shown on the profile page.
    'profile_json' => [
        'identifiers', 'addresses', 'licenses', 'credentials', 'exclusions',
        'accounts', 'aliases', 'source_records', 'resolutions',
    ],

    'cache_ttl' => 60, // seconds for the stats board

    // Wall-clock seconds allowed for a streamed export. Must exceed PHP's
    // max_execution_time (30s by default) or the download is killed mid-stream by
    // a fatal that no catch block can intercept, leaving a debug page inside the
    // .csv. Finite on purpose so a runaway export still terminates.
    'export_time_limit' => 300,

    // Statement budget for an export, in milliseconds. Deliberately much larger
    // than the interactive search budget: a capped 10k-row download is expected to
    // take longer than a page view, and reusing the 15s page budget truncated real
    // exports to nothing but an error marker.
    // Per-statement, and it applies to EVERY lazy page, not to the export as a
    // whole — 20 pages at 120s would be a 2400s ceiling under a 300s wall clock.
    // Kept well below export_time_limit so one slow page cannot consume the whole
    // budget; the controller also stops at its own deadline as a backstop.
    'export_timeout_ms' => 30000,

    // Seconds to remember whether a source match id is linked to an identity.
    // The check is an unindexed ~3.21M-row scan (~2.24s), and one profile view
    // asks for several match payloads, so without this the gate dominates the
    // page. Link membership is stable within a viewing session.
    'match_gate_ttl' => 300,
];
