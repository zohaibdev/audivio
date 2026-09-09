<?php

/*
|--------------------------------------------------------------------------
| Password-protected report sharing (§50)
|--------------------------------------------------------------------------
*/

return [
    /*
    | Token length in raw bytes, before hex encoding. 32 bytes is 256 bits
    | of entropy from a CSPRNG, which makes enumeration not merely
    | impractical but arithmetically hopeless — the rate limit on the
    | lookup endpoint exists to stop someone wasting our capacity trying,
    | not because the token could actually fall.
    */
    'token_bytes' => 32,

    /*
    | The minimum a share password may be. Shorter than the account
    | password rule on purpose: this is a secret typed once from an email
    | or a message, often on a phone, and a rule so strict that owners
    | route around it by reusing their own password would be worse.
    */
    'min_password_length' => 8,

    /*
    | How long an unlocked session lasts before the recipient re-enters
    | the password. Long enough to read a long report without being
    | interrupted; short enough that a shared device does not stay
    | unlocked all day.
    */
    'access_ttl_minutes' => (int) env('SHARE_ACCESS_TTL_MINUTES', 120),

    'lockout' => [
        /*
        | Failed password attempts allowed before the link locks, and for
        | how long. Counted per token *and* per IP: per token alone would
        | let one attacker lock a link out for its legitimate recipient,
        | and per IP alone would let a distributed attempt walk past it.
        */
        'max_attempts' => (int) env('SHARE_MAX_PASSWORD_ATTEMPTS', 5),
        'decay_minutes' => (int) env('SHARE_LOCKOUT_MINUTES', 15),
    ],

    /*
    | Requests per minute against the token-lookup endpoint. Bounded
    | because that endpoint is the only one that reveals whether a token
    | exists at all.
    */
    'lookup_rate_limit' => env('SHARE_LOOKUP_RATE_LIMIT', '30,1'),

    /*
    | Where a share link points. The path deliberately contains no
    | numeric audit id — see ShareTokenService.
    */
    'url_template' => env('SHARE_URL_TEMPLATE', '/shared-report/{token}'),
];
