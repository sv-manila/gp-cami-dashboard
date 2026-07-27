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

    // Master person/profile table used for search + profile display.
    'profile_table' => 'gp_identity_profile',

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
        'Source links'        => 'gp_source_link',
        'Licenses'            => 'gp_license',
        'Addresses'           => 'gp_address',
        'Credential links'    => 'gp_identity_credential',
        'Exclusion links'     => 'gp_identity_exclusion',
        'Identifiers'         => 'gp_identity_identifier',
        'Resolutions'         => 'gp_identity_resolution',
        'Source systems'      => 'gp_source_system',
    ],

    // One-line explanation per stat (label => help text), shown on hover.
    'stats_help' => [
        'Identity profiles' => 'Denormalized read model — one wide, ready-to-serve row per resolved person (what this dashboard and the API read).',
        'Identities'        => 'Distinct real people after resolution. Many source records collapse into one identity.',
        'Staged persons'    => 'Source employee rows mapped into the canonical staging shape before resolution. Roughly one per source record.',
        'Source links'      => 'Each source record bound to the identity it resolved to, with the match method, key and confidence.',
        'Licenses'          => 'Professional licenses (number + state + board) attached to identities, deduplicated across sources.',
        'Addresses'         => 'Mailing / practice / alternate addresses attached to identities.',
        'Credential links'  => 'Credential-verification matches rolled up to an identity (confirmed links) — a person’s registry credential status.',
        'Exclusion links'   => 'Exclusion-list hits rolled up to an identity (candidate links) — potential OIG/SAM/state exclusions to review.',
        'Identifiers'       => 'Multi-valued match keys such as DEA and MMIS numbers (from employee_additional_info); a shared value merges identities.',
        'Resolutions'       => 'Steward decisions recorded per identity (confirm / split / relink) — the human review trail.',
        'Source systems'    => 'Registered upstream systems feeding the hub, each with a reliability rank used in survivorship.',
    ],

    // JSON rollup columns on gp_identity_profile shown on the profile page.
    'profile_json' => [
        'identifiers', 'addresses', 'licenses', 'credentials', 'exclusions',
        'accounts', 'aliases', 'source_records', 'resolutions',
    ],

    'cache_ttl' => 60, // seconds for the stats board
];
