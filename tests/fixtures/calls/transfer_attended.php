<?php
/*
 * Queue call answered by 1001; at +40 s 1001 consults 1002 (1002 rings 5 s, they talk 9 s), then
 * completes an attended transfer at +54 s; 1002 talks 60 s more with the caller.
 * SYNTHETIC (the sample has no transfers), built by the Asterisk 18 formats:
 *   queue_log  ATTENDEDTRANSFER|BRIDGE|<bridge uniqueid>|<holdtime>|<calltime>|<origposition>
 *              (the target is not in the event, it comes from CDR);
 *   CDR        consultation row PJSIP/1001 -> PJSIP/1002, then a row for the transferee
 *              (Local/1001@from-queue;2) bridged to PJSIP/1002.
 * Customer number 900000022 is invented.
 */
return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        ['2026-10-21 16:10:00', '900000022', '1000', 'ext-queues', 'PJSIP/552020515-0000b101', 'Local/1001@from-queue-0000f201;1', 54, 54, 'ANSWERED', '1792581000.250101', '', '1792581000.250101', 250101],
        ['2026-10-21 16:10:00', '900000022', '1001', 'ext-local', 'Local/1001@from-queue-0000f201;2', 'PJSIP/1001-0000b102', 54, 50, 'ANSWERED', '1792581000.250103', '', '1792581000.250101', 250103],
        ['2026-10-21 16:10:40', '1001', '1002', 'ext-local', 'PJSIP/1001-0000b104', 'PJSIP/1002-0000b105', 14, 9, 'ANSWERED', '1792581040.250104', '', '1792581000.250101', 250104],
        ['2026-10-21 16:10:54', '900000022', '1002', 'ext-local', 'Local/1001@from-queue-0000f201;2', 'PJSIP/1002-0000b105', 60, 60, 'ANSWERED', '1792581000.250103', '', '1792581000.250101', 250106],
    ],
    'qlog' => [
        '1792581000|1792581000.250101|1000|NONE|ENTERQUEUE||900000022|1',
        '1792581004|1792581000.250101|1000|1001|CONNECT|4|1792581000.250103|4',
        '1792581054|1792581000.250101|1000|1001|ATTENDEDTRANSFER|BRIDGE|0f7c1a2e-5b8d-4c3e-9a10-1d2e3f405162|4|50|1',
    ],
];
