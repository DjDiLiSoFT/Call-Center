<?php
/*
 * Direct inbound call from the trunk to extension 1001, answered.
 * The 3-day sample contains no direct inbound calls, so this row is SYNTHETIC: it follows the
 * shape of the real ext-local rows (e.g. linkedid 1792321614.223166) with the trunk channel
 * PJSIP/552020515-… as the caller. Customer number 900000005 (invented).
 */
return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        ['2026-10-20 11:00:00', '900000005', '1001', 'ext-local', 'PJSIP/552020515-00009a01', 'PJSIP/1001-00009a02', 25, 20, 'ANSWERED', '1792494000.300001', 'external-1001-900000005-20261020-110000-1792494000.300001.wav', '1792494000.300001', 300001],
    ],
    'qlog' => [],
];
