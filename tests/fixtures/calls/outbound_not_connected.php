<?php
/*
 * Outbound call from 1002 via the trunk, not connected. Real row: linkedid 1792321133.222930
 * (2026-10-18); dialed number replaced by 900000008.
 */
return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        ['2026-10-18 15:58:53', '552020515', '900000008', 'from-internal', 'PJSIP/1002-00008e71', 'PJSIP/552020515-00008e72', 54, 0, 'NO ANSWER', '1792321133.222930', 'out-900000008-1002-20261018-155853-1792321133.222930.wav', '1792321133.222930', 188288],
    ],
    'qlog' => [],
];
