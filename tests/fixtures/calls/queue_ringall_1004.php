<?php
/*
 * Ring-all queue call answered by 1004. CDR rows copied from the real sample
 * (linkedid 1792321160.222942, 2026-10-18), customer number replaced by 900000001.
 *
 * The sample queue_log.txt does not cover 2026-10-18, so the qlog events below are
 * RECONSTRUCTED from the CDR facts (Asterisk 18 formats): ENTERQUEUE, RINGNOANSWER|0 for
 * offline 1003/1005/1009/1010 (CDR: FAILED, duration 0), RINGCANCELED for 1001/1002
 * (CDR: NO ANSWER, 3 s), CONNECT 1004 (hold 3, ringtime 3), COMPLETECALLER (talk 30 = billsec).
 */
$rec = 'q-1000-900000001-20261018-155920-1792321160.222942.wav';
$id = '1792321160.222942';
$t = '2026-10-18 15:59:20';

return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        [$t, '900000001', '1002', 'ext-local', 'Local/1002@from-queue-0000ce7b;2', 'PJSIP/1002-00008e76', 3, 0, 'NO ANSWER', '1792321160.222948', '', $id, 188299],
        [$t, '900000001', '1001', 'ext-local', 'Local/1001@from-queue-0000ce7e;2', 'PJSIP/1001-00008e77', 3, 0, 'NO ANSWER', '1792321160.222954', '', $id, 188308],
        [$t, '900000001', '1004', 'ext-local', 'Local/1004@from-queue-0000ce7f;2', 'PJSIP/1004-00008e78', 33, 30, 'ANSWERED', '1792321160.222956', $rec, $id, 188311],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1010@from-queue-0000ce79;1', 0, 0, 'FAILED', $id, $rec, $id, 188292],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1003@from-queue-0000ce7a;1', 0, 0, 'FAILED', $id, $rec, $id, 188297],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1002@from-queue-0000ce7b;1', 3, 3, 'NO ANSWER', $id, $rec, $id, 188300],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1009@from-queue-0000ce7c;1', 0, 0, 'FAILED', $id, $rec, $id, 188303],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1005@from-queue-0000ce7d;1', 0, 0, 'FAILED', $id, $rec, $id, 188306],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1001@from-queue-0000ce7e;1', 3, 3, 'NO ANSWER', $id, $rec, $id, 188309],
        [$t, '900000001', '1000', 'ext-queues', 'PJSIP/552020515-00008e75', 'Local/1004@from-queue-0000ce7f;1', 33, 33, 'ANSWERED', $id, $rec, $id, 188312],
    ],
    'qlog' => [
        '1792321160|1792321160.222942|1000|NONE|DID|552020515',
        '1792321160|1792321160.222942|1000|NONE|ENTERQUEUE||900000001|1',
        '1792321160|1792321160.222942|1000|1010|RINGNOANSWER|0',
        '1792321160|1792321160.222942|1000|1003|RINGNOANSWER|0',
        '1792321160|1792321160.222942|1000|1009|RINGNOANSWER|0',
        '1792321160|1792321160.222942|1000|1005|RINGNOANSWER|0',
        '1792321163|1792321160.222942|1000|1004|CONNECT|3|1792321160.222956|3',
        '1792321163|1792321160.222942|1000|1002|RINGCANCELED|3012',
        '1792321163|1792321160.222942|1000|1001|RINGCANCELED|3011',
        '1792321193|1792321160.222942|1000|1004|COMPLETECALLER|3|30|1',
    ],
];
