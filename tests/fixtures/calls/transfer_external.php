<?php
/*
 * Queue call answered by 1001, blind-transferred after 30 s to the external number 900000099
 * (goes out through the trunk). SYNTHETIC (the sample has no transfers), Asterisk 18 formats:
 *   queue_log  BLINDTRANSFER|900000099|from-internal|<holdtime>|<calltime>|<origposition>;
 *   CDR        the caller channel gets a from-internal row with dst = the external number.
 * Customer number 900000021 and target 900000099 are invented.
 */
return [
    // calldate, src, dst, dcontext, channel, dstchannel, duration, billsec, disposition, uniqueid, recordingfile, linkedid, sequence
    'cdr' => [
        ['2026-10-21 16:26:40', '900000021', '1000', 'ext-queues', 'PJSIP/552020515-0000b201', 'Local/1001@from-queue-0000f301;1', 33, 33, 'ANSWERED', '1792582000.250201', '', '1792582000.250201', 250201],
        ['2026-10-21 16:26:40', '900000021', '1001', 'ext-local', 'Local/1001@from-queue-0000f301;2', 'PJSIP/1001-0000b202', 33, 30, 'ANSWERED', '1792582000.250203', '', '1792582000.250201', 250203],
        ['2026-10-21 16:27:13', '900000021', '900000099', 'from-internal', 'PJSIP/552020515-0000b201', 'PJSIP/552020515-0000b205', 50, 45, 'ANSWERED', '1792582033.250205', '', '1792582000.250201', 250205],
    ],
    'qlog' => [
        '1792582000|1792582000.250201|1000|NONE|ENTERQUEUE||900000021|1',
        '1792582003|1792582000.250201|1000|1001|CONNECT|3|1792582000.250203|3',
        '1792582033|1792582000.250201|1000|1001|BLINDTRANSFER|900000099|from-internal|3|30|1',
    ],
];
