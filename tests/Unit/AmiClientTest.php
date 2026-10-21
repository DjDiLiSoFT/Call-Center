<?php

namespace CallStats\Tests\Unit;

use CallStats\Live\AmiClient;
use PHPUnit\Framework\TestCase;

class AmiClientTest extends TestCase
{
    public function test_parse_splits_messages_into_key_value_arrays(): void
    {
        $raw = "Response: Success\r\nActionID: 1\r\nEventList: start\r\n\r\n"
            . "Event: QueueEntry\r\nQueue: 1000\r\nCallerIDNum: 900000001\r\nWait: 12\r\n\r\n";
        $msgs = AmiClient::parse($raw);
        $this->assertCount(2, $msgs);
        $this->assertSame('Success', $msgs[0]['Response']);
        $this->assertSame('900000001', $msgs[1]['CallerIDNum']);
        $this->assertSame('12', $msgs[1]['Wait']);
    }

    public function test_request_collects_event_list_until_complete(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        [$host, $port] = explode(':', stream_socket_get_name($server, false));

        $client = new AmiClient($host, (int) $port, 'u', 's', 2.0);
        $client->connectOnly();
        $conn = stream_socket_accept($server, 2);
        fwrite($conn, "Asterisk Call Manager/7.0.3\r\n"
            . "Response: Success\r\nActionID: cs1\r\nMessage: Authentication accepted\r\n\r\n"
            . "Response: Success\r\nActionID: cs2\r\nEventList: start\r\nMessage: Queue status will follow\r\n\r\n"
            . "Event: QueueParams\r\nActionID: cs2\r\nQueue: 1000\r\nCalls: 1\r\n\r\n"
            . "Event: QueueEntry\r\nActionID: cs2\r\nQueue: 1000\r\nWait: 7\r\n\r\n"
            . "Event: QueueStatusComplete\r\nActionID: cs2\r\nEventList: Complete\r\n\r\n");

        $client->login();
        $events = $client->request('QueueStatus');
        fclose($conn);
        fclose($server);

        $this->assertSame(['QueueParams', 'QueueEntry'], array_column($events, 'Event'));
        $this->assertSame('7', $events[1]['Wait']);
    }

    public function test_failed_login_throws(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        [$host, $port] = explode(':', stream_socket_get_name($server, false));
        $client = new AmiClient($host, (int) $port, 'u', 'bad', 2.0);
        $client->connectOnly();
        $conn = stream_socket_accept($server, 2);
        fwrite($conn, "Asterisk Call Manager/7.0.3\r\nResponse: Error\r\nActionID: cs1\r\nMessage: Authentication failed\r\n\r\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication failed');
        try {
            $client->login();
        } finally {
            fclose($conn);
            fclose($server);
        }
    }

    public function test_unreachable_host_throws(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [$host, $port] = explode(':', stream_socket_get_name($server, false));
        fclose($server);
        $this->expectException(\RuntimeException::class);
        (new AmiClient($host, (int) $port, 'u', 's', 0.5))->connectOnly();
    }
}
