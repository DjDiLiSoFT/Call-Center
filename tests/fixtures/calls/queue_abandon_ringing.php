<?php
/*
 * Caller hangs up while 1001 is still ringing: only RINGCANCELED for 1001, then ABANDON.
 * Format modeled on real linkedid 1792555462.239549 (2026-10-21) final round.
 * Customer number replaced by 900000003.
 */
return [
    'cdr' => [],
    'qlog' => [
        '1792555462|1792555462.239549|1000|NONE|DID|552020515',
        '1792555462|1792555462.239549|1000|NONE|ENTERQUEUE||900000003|1',
        '1792555470|1792555462.239549|1000|1001|RINGCANCELED|8012',
        '1792555470|1792555462.239549|1000|NONE|ABANDON|1|1|8',
    ],
];
