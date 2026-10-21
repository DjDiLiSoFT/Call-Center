<?php
/*
 * Direct inbound call from the trunk to extension 1001, not answered (rang 20 s).
 * SYNTHETIC (no direct inbound calls in the 3-day sample), shaped like real ext-local rows.
 * Customer number 900000006 (invented).
 */
return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        ['2026-10-20 12:00:00', '900000006', '1001', 'ext-local', 'PJSIP/552020515-00009a10', 'PJSIP/1001-00009a11', 20, 0, 'NO ANSWER', '1792497600.300010', '', '1792497600.300010', 300010],
    ],
    'qlog' => [],
];
