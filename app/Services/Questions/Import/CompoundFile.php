<?php

namespace App\Services\Questions\Import;

use RuntimeException;

/**
 * Reads streams out of an OLE compound file (the format of old .doc files and of the objects
 * Word embeds in a .docx, such as an Equation 3.0 or MathType equation's oleObject1.bin).
 * Read-only and bounded: chains are followed with loop protection and sizes are capped.
 */
class CompoundFile
{
    private const FREE = 0xFFFFFFFF;

    private const END = 0xFFFFFFFE;

    private const MAX_STREAM = 8 * 1024 * 1024;

    private int $sectorSize;

    private int $miniSize;

    private int $miniCutoff;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $ministream = '';

    /** @var array<string, array{start: int, size: int, type: int}> */
    private array $entries = [];

    public function __construct(private string $data)
    {
        if (strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Not an OLE compound file.');
        }
        $h = unpack('vshift/vminishift', substr($data, 0x1E, 4));
        $this->sectorSize = 1 << $h['shift'];
        $this->miniSize = 1 << $h['minishift'];
        if (! in_array($this->sectorSize, [512, 4096], true) || $this->miniSize !== 64) {
            throw new RuntimeException('Unsupported compound file.');
        }
        $f = unpack('VnumFat/VdirStart/Vtx/Vcutoff/VminiFatStart/VnumMiniFat/VdifatStart/VnumDifat', substr($data, 0x2C, 32));
        $this->miniCutoff = $f['cutoff'] ?: 4096;

        // the sector list of the FAT: 109 entries in the header, then the DIFAT chain
        $fatSectors = array_values(unpack('V109', substr($data, 0x4C, 436)));
        $next = $f['difatStart'];
        $per = intdiv($this->sectorSize, 4) - 1;
        for ($i = 0; $i < $f['numDifat'] && $next < self::END && $i < 1000; $i++) {
            $vals = array_values(unpack('V'.($per + 1), $this->sector($next)));
            $next = array_pop($vals);
            array_push($fatSectors, ...$vals);
        }
        foreach (array_slice($fatSectors, 0, $f['numFat']) as $s) {
            if ($s < self::END) {
                array_push($this->fat, ...array_values(unpack('V'.intdiv($this->sectorSize, 4), $this->sector($s))));
            }
        }
        if ($f['miniFatStart'] < self::END) {
            $mf = $this->chain($f['miniFatStart'], PHP_INT_MAX);
            $this->miniFat = array_values(unpack('V'.intdiv(strlen($mf), 4), $mf));
        }

        $dir = $this->chain($f['dirStart'], PHP_INT_MAX);
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $len = unpack('v', substr($dir, $o + 64, 2))[1];
            $type = ord($dir[$o + 66]);
            if ($type === 0 || $len < 2 || $len > 64) {
                continue;
            }
            $name = mb_convert_encoding(substr($dir, $o, $len - 2), 'UTF-8', 'UTF-16LE');
            $e = unpack('Vstart/Vsize', substr($dir, $o + 116, 8));
            if ($type === 5) {
                $this->ministream = $this->chain($e['start'], $e['size']);

                continue;
            }
            $this->entries[$name] ??= ['start' => $e['start'], 'size' => $e['size'], 'type' => $type];
        }
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]) && $this->entries[$name]['type'] === 2;
    }

    public function stream(string $name): string
    {
        $e = $this->entries[$name] ?? throw new RuntimeException("No stream named $name.");
        if ($e['size'] > self::MAX_STREAM) {
            throw new RuntimeException('Stream too large.');
        }
        if ($e['size'] < $this->miniCutoff) {
            $out = '';
            $s = $e['start'];
            $seen = [];
            while ($s < self::END && strlen($out) < $e['size'] && ! isset($seen[$s])) {
                $seen[$s] = true;
                $out .= substr($this->ministream, $s * $this->miniSize, $this->miniSize);
                $s = $this->miniFat[$s] ?? self::END;
            }

            return substr($out, 0, $e['size']);
        }

        return $this->chain($e['start'], $e['size']);
    }

    private function sector(int $n): string
    {
        return (string) substr($this->data, ($n + 1) * $this->sectorSize, $this->sectorSize);
    }

    private function chain(int $start, int $size): string
    {
        $out = '';
        $seen = [];
        $s = $start;
        while ($s < self::END && $s !== self::FREE && ! isset($seen[$s]) && strlen($out) < min($size, self::MAX_STREAM)) {
            $seen[$s] = true;
            $out .= $this->sector($s);
            $s = $this->fat[$s] ?? self::END;
        }

        return $size === PHP_INT_MAX ? $out : substr($out, 0, $size);
    }
}
