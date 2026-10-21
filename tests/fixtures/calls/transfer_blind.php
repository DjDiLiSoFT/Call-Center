<?php
/*
 * Queue call answered by 1001, blind-transferred to 1002 after 40 s; 1002 talks 60 s.
 * SYNTHETIC: the 3-day sample has no transfer at all (0 TRANSFER/BLINDTRANSFER/ATTENDEDTRANSFER
 * events in queue_log; the only queue linkedid with two ANSWERED phone legs, 1792397534.227940, is a
 * ring-all pickup race: 1001's leg is ANSWERED with billsec 0). Built by the Asterisk 18 formats:
 *   queue_log  BLINDTRANSFER|<ext>|<context>|<holdtime>|<calltime>|<origposition>, no COMPLETE* after it;
 *   CDR        the transferee (caller channel after Local optimisation) gets a new row in the same
 *              linkedid that dials the target phone.
 * Customer number 900000020 is invented.
 */
return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        ['2026-10-21 15:53:20', '900000020', '1000', 'ext-queues', 'PJSIP/552020515-0000b001', 'Local/1001@from-queue-0000f101;1', 44, 44, 'ANSWERED', '1792580000.250001', 'q-1000-900000020-20261021-155320-1792580000.250001.wav', '1792580000.250001', 250001],
        ['2026-10-21 15:53:20', '900000020', '1000', 'ext-queues', 'PJSIP/552020515-0000b001', 'Local/1002@from-queue-0000f102;1', 4, 4, 'NO ANSWER', '1792580000.250001', 'q-1000-900000020-20261021-155320-1792580000.250001.wav', '1792580000.250001', 250002],
        ['2026-10-21 15:53:20', '900000020', '1001', 'ext-local', 'Local/1001@from-queue-0000f101;2', 'PJSIP/1001-0000b002', 44, 40, 'ANSWERED', '1792580000.250003', 'q-1000-900000020-20261021-155320-1792580000.250001.wav', '1792580000.250001', 250003],
        ['2026-10-21 15:53:20', '900000020', '1002', 'ext-local', 'Local/1002@from-queue-0000f102;2', 'PJSIP/1002-0000b003', 4, 0, 'NO ANSWER', '1792580000.250005', '', '1792580000.250001', 250005],
        // after the blind transfer: the caller channel dials 1002, rings 5 s, talk 60 s
        ['2026-10-21 15:54:04', '900000020', '1002', 'ext-local', 'PJSIP/552020515-0000b001', 'PJSIP/1002-0000b006', 65, 60, 'ANSWERED', '1792580044.250007', 'q-1000-900000020-20261021-155320-1792580000.250001.wav', '1792580000.250001', 250007],
    ],
    'qlog' => [
        '1792580000|1792580000.250001|1000|NONE|ENTERQUEUE||900000020|1',
        '1792580004|1792580000.250001|1000|1001|CONNECT|4|1792580000.250003|4',
        '1792580004|1792580000.250001|1000|1002|RINGCANCELED|4000',
        '1792580044|1792580000.250001|1000|1001|BLINDTRANSFER|1002|from-internal|4|40|1',
    ],
];
