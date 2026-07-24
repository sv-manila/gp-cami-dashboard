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
        'Resolutions'         => 'gp_identity_resolution',
        'Source systems'      => 'gp_source_system',
    ],

    // JSON rollup columns on gp_identity_profile shown on the profile page.
    'profile_json' => [
        'addresses', 'licenses', 'credentials', 'exclusions',
        'accounts', 'aliases', 'source_records', 'resolutions',
    ],

    'cache_ttl' => 60, // seconds for the stats board
];
