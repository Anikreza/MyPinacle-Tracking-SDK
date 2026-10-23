<?php

/*
 * Package defaults. No tracking keys live here: pixel ids, tokens, test codes and switches are read from each app's
 * database through its TrackingSettings adapter.
 */
return [
    // HMAC secret for server event ids. Defaults to the app key; both apps share one database, not one APP_KEY, so ids
    // are verified by the app that minted them.
    'event_id_secret' => null,

    // Send-once ledger: table in the shared database (Inventory migration), falls back to the cache when it is missing.
    'ledger_table' => 'tracking_event_claims',
    'ledger_ttl_days' => 7,

    // Outgoing HTTP: short timeouts, never blocks the shopper (requests run queued or after the response).
    'http' => ['timeout' => 8, 'connect_timeout' => 4, 'retries' => 2, 'retry_sleep_ms' => 500],
];
