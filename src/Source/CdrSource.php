<?php

namespace CallStats\Source;

use PDO;

/**
 * Read-only access to asteriskcdrdb.cdr.
 */
class CdrSource
{
    private const BATCH = 500;

    /** @var PDO */
    private $src;

    public function __construct(PDO $src)
    {
        $this->src = $src;
    }

    /**
     * Distinct linkedids of CDR rows with calldate in [$from, $to] ('Y-m-d H:i:s').
     *
     * @return string[]
     */
    public function linkedidsBetween(string $from, string $to): array
    {
        $st = $this->src->prepare(
            "SELECT DISTINCT linkedid FROM cdr WHERE calldate >= ? AND calldate <= ? AND linkedid <> ''"
        );
        $st->execute([$from, $to]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * All CDR rows of the given linkedids, ordered by calldate, sequence.
     *
     * cdr has no index on linkedid. A linkedid is "<epoch>.<n>" of the first channel, and no row
     * of the call can start before it, so every batch is limited to a calldate range around the
     * linkedid epochs (the calldate index is used): [min epoch - 1 h, max epoch + 1 day].
     *
     * @param string[] $linkedids
     * @return array<string,array[]> linkedid => rows
     */
    public function rowsFor(array $linkedids): array
    {
        $out = [];
        $linkedids = array_values(array_unique(array_map('strval', $linkedids)));
        foreach (array_chunk($linkedids, self::BATCH) as $batch) {
            $sql = 'SELECT * FROM cdr WHERE linkedid IN (' . implode(',', array_fill(0, count($batch), '?')) . ')';
            $params = $batch;
            $range = $this->epochRange($batch);
            if ($range !== null) {
                $sql .= ' AND calldate >= ? AND calldate <= ?';
                $params[] = date('Y-m-d H:i:s', $range[0] - 3600);
                $params[] = date('Y-m-d H:i:s', $range[1] + 86400);
            }
            $sql .= ' ORDER BY calldate, sequence';
            $st = $this->src->prepare($sql);
            $st->execute($params);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $out[(string) $row['linkedid']][] = $row;
            }
        }
        return $out;
    }

    /** @return int[]|null [min, max] epoch of linkedids, null if any is not "<epoch>.<n>" */
    private function epochRange(array $ids): ?array
    {
        $min = null;
        $max = null;
        foreach ($ids as $id) {
            if (!preg_match('/^(\d{9,11})\.\d+$/', $id, $m)) {
                return null;
            }
            $t = (int) $m[1];
            $min = $min === null ? $t : min($min, $t);
            $max = $max === null ? $t : max($max, $t);
        }
        return $min === null ? null : [$min, $max];
    }
}
