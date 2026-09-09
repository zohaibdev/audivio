<?php

/*
|--------------------------------------------------------------------------
| White-label branding (§48)
|--------------------------------------------------------------------------
| Where an agency's logo is stored and how large it may be.
|
| The disk is private for the same reason screenshots are: a path keyed by
| user id is guessable, and confirming which ids have logos is confirming
| which accounts exist.
*/

return [
    'logo_disk' => env('BRANDING_LOGO_DISK', 'local'),

    /*
    | Logos are inlined into PDFs as base64 data URIs — dompdf cannot
    | fetch a remote asset, and the file sits behind a signed route it has
    | no session for. Base64 inflates by roughly a third, so this bound is
    | really a bound on PDF size.
    */
    'max_logo_bytes' => (int) env('BRANDING_MAX_LOGO_BYTES', 512 * 1024),

    'allowed_logo_mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'],
];
