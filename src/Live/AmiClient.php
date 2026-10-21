<?php

namespace CallStats\Live;

use RuntimeException;

/**
 * Minimal Asterisk Manager Interface client: login with events off, run list actions, logoff.
 * Only read-only actions are sent by this application (QueueStatus, CoreShowChannels,
 * ExtensionStateList).
 */
class AmiClient
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $user;
    /** @var string */
    private $secret;
    /** @var float */
    private $timeout;
    /** @var resource|null */
    private $sock;
    /** @var string */
    private $buffer = '';
    /** @var int */
    private $seq = 0;

    public function __construct(string $host, int $port, string $user, string $secret, float $timeout = 3.0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->secret = $secret;
        $this->timeout = $timeout;
    }

    /** Opens the TCP connection without logging in. */
    public function connectOnly(): void
    {
        $sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
        if ($sock === false) {
            throw new RuntimeException("AMI недоступен ({$this->host}:{$this->port}): $errstr");
        }
        stream_set_timeout($sock, (int) ceil($this->timeout));
        $this->sock = $sock;
        $this->buffer = '';
    }

    public function connect(): void
    {
        $this->connectOnly();
        $this->login();
    }

    public function login(): void
    {
        $reply = $this->send('Login', ['Username' => $this->user, 'Secret' => $this->secret, 'Events' => 'off']);
        if (($reply['Response'] ?? '') !== 'Success') {
            throw new RuntimeException('AMI: ' . ($reply['Message'] ?? 'ошибка входа'));
        }
    }

    /**
     * Runs an action. For list actions (response with "EventList: start") returns the events up
     * to, but not including, the "EventList: Complete" event; otherwise returns [response].
     *
     * @return array<int, array<string, string>>
     */
    public function request(string $action, array $params = []): array
    {
        $id = $this->nextId();
        $first = $this->send($action, $params, $id);
        if (($first['Response'] ?? '') === 'Error') {
            throw new RuntimeException("AMI $action: " . ($first['Message'] ?? 'ошибка'));
        }
        if (strtolower($first['EventList'] ?? '') !== 'start') {
            return [$first];
        }
        $events = [];
        while (true) {
            $msg = $this->readMessage();
            if (($msg['ActionID'] ?? '') !== $id) {
                continue;
            }
            if (strtolower($msg['EventList'] ?? '') === 'complete') {
                return $events;
            }
            $events[] = $msg;
        }
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fwrite($this->sock, "Action: Logoff\r\n\r\n");
            fclose($this->sock);
            $this->sock = null;
        }
    }

    /**
     * Splits raw AMI text into messages (blank-line separated) of "Key: Value" lines.
     *
     * @return array<int, array<string, string>>
     */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split("/\r?\n\r?\n/", $raw) as $block) {
            $msg = self::parseBlock($block);
            if ($msg !== []) {
                $out[] = $msg;
            }
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function parseBlock(string $block): array
    {
        $msg = [];
        foreach (preg_split("/\r?\n/", $block) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $msg[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
        return $msg;
    }

    private function nextId(): string
    {
        return 'cs' . (++$this->seq);
    }

    /** @return array<string, string> the response with the matching ActionID */
    private function send(string $action, array $params, ?string $id = null): array
    {
        if ($this->sock === null) {
            throw new RuntimeException('AMI: нет соединения');
        }
        $id = $id ?? $this->nextId();
        $text = "Action: $action\r\nActionID: $id\r\n";
        foreach ($params as $k => $v) {
            $text .= $k . ': ' . str_replace(["\r", "\n"], '', (string) $v) . "\r\n";
        }
        fwrite($this->sock, $text . "\r\n");
        while (true) {
            $msg = $this->readMessage();
            if (isset($msg['Response']) && ($msg['ActionID'] ?? '') === $id) {
                return $msg;
            }
        }
    }

    /** @return array<string, string> */
    private function readMessage(): array
    {
        while (true) {
            if (preg_match("/\r?\n\r?\n/", $this->buffer, $m, PREG_OFFSET_CAPTURE)) {
                $end = $m[0][1];
                $block = substr($this->buffer, 0, $end);
                $this->buffer = (string) substr($this->buffer, $end + strlen($m[0][0]));
                $msg = self::parseBlock($block);
                if ($msg !== []) {
                    return $msg;
                }
                continue;
            }
            $chunk = fread($this->sock, 65536);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                throw new RuntimeException($meta['timed_out'] ? 'AMI: нет ответа (таймаут)' : 'AMI: соединение закрыто');
            }
            // The greeting line ("Asterisk Call Manager/x.y") has no blank line after it.
            $this->buffer .= preg_replace('/^Asterisk Call Manager\/[^\r\n]*\r?\n/', '', $chunk);
        }
    }
}
