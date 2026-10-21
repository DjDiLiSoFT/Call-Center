<?php

namespace CallStats\Store;

use PDO;

/**
 * Table `operators`: FreePBX name (synced) and own name set in the service.
 */
class OperatorRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @param array<string,string> $extToName updates fpbx_name, never touches custom_name */
    public function sync(array $extToName): void
    {
        $st = $this->db->prepare(
            'INSERT INTO operators (extension, fpbx_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE fpbx_name = VALUES(fpbx_name)'
        );
        foreach ($extToName as $ext => $name) {
            $st->execute([(string) $ext, mb_substr((string) $name, 0, 80)]);
        }
    }

    /** @return array[] rows extension, fpbx_name, custom_name ordered by extension */
    public function all(): array
    {
        return $this->db->query(
            'SELECT extension, fpbx_name, custom_name FROM operators ORDER BY LENGTH(extension), extension'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sets (or clears with null / '') the own name of an operator. */
    public function setName(string $ext, ?string $name): void
    {
        $name = $name === null ? null : trim($name);
        if ($name === '') {
            $name = null;
        }
        if ($name !== null) {
            $name = mb_substr($name, 0, 80);
        }
        $st = $this->db->prepare(
            'INSERT INTO operators (extension, custom_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE custom_name = VALUES(custom_name)'
        );
        $st->execute([$ext, $name]);
    }

    /** custom name -> FreePBX name (if it is not just the number) -> the number. */
    public function displayName(string $ext): string
    {
        $st = $this->db->prepare('SELECT fpbx_name, custom_name FROM operators WHERE extension = ?');
        $st->execute([$ext]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return $ext;
        }
        $custom = trim((string) $r['custom_name']);
        if ($custom !== '') {
            return $custom;
        }
        $fpbx = trim((string) $r['fpbx_name']);
        if ($fpbx !== '' && $fpbx !== $ext) {
            return $fpbx;
        }
        return $ext;
    }
}
