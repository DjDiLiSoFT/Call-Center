<?php
/*
 * Unanswered queue call: 1005 is offline (RINGNOANSWER|0, as on every call on this PBX),
 * 1001 really rang; the call times out of the queue (EXITWITHTIMEOUT).
 * Customer number 900000004 (invented).
 */
return [
    'cdr' => [],
    'qlog' => [
        '1792560000|1792560000.240001|1000|NONE|ENTERQUEUE||900000004|1',
        '1792560000|1792560000.240001|1000|1005|RINGNOANSWER|0',
        '1792560015|1792560000.240001|1000|1001|RINGNOANSWER|15000',
        '1792560020|1792560000.240001|1000|NONE|EXITWITHTIMEOUT|1|1|20',
    ],
];
