<?php

namespace PhamPhu232\QueryLogger\Support;

class Ulid
{
    private static $time = '';
    private static $rand = [];

    public const BASE10 = [
        '' => '0123456789',
        '0' => 0,
        '1' => 1,
        '2' => 2,
        '3' => 3,
        '4' => 4,
        '5' => 5,
        '6' => 6,
        '7' => 7,
        '8' => 8,
        '9' => 9,
    ];

    public static function fromBase($digits, $map)
    {
        $base = \strlen($map['']);
        $count = \strlen($digits);
        $bytes = [];

        while ($count) {
            $quotient = [];
            $remainder = 0;

            for ($i = 0; $i !== $count; $i++) {
                $carry = ($bytes ? $digits[$i] : $map[$digits[$i]]) + $remainder * $base;

                if (\PHP_INT_SIZE >= 8) {
                    $digit = $carry >> 16;
                    $remainder = $carry & 0xFFFF;
                } else {
                    $digit = $carry >> 8;
                    $remainder = $carry & 0xFF;
                }

                if ($digit || $quotient) {
                    $quotient[] = $digit;
                }
            }

            $bytes[] = $remainder;
            $count = \count($digits = $quotient);
        }

        return pack(\PHP_INT_SIZE >= 8 ? 'n*' : 'C*', ...array_reverse($bytes));
    }

    public static function generate($time = null)
    {
        if ($time !== null && !($time instanceof \DateTimeInterface)) {
            throw new \InvalidArgumentException('Expected instance of DateTimeInterface or null.');
        }

        if (null === $mtime = $time) {
            $time = microtime(false);
            $time = substr($time, 11) . substr($time, 2, 3);
        } elseif (0 > $time = $time->format('Uv')) {
            throw new \InvalidArgumentException('The timestamp must be positive.');
        }

        if ($time > self::$time || (null !== $mtime && $time !== self::$time)) {
            randomize:
            $r = unpack('n*', random_bytes(10));
            $r[1] |= ($r[5] <<= 4) & 0xF0000;
            $r[2] |= ($r[5] <<= 4) & 0xF0000;
            $r[3] |= ($r[5] <<= 4) & 0xF0000;
            $r[4] |= ($r[5] <<= 4) & 0xF0000;
            unset($r[5]);
            self::$rand = $r;
            self::$time = $time;
        } elseif ([1 => 0xFFFFF, 0xFFFFF, 0xFFFFF, 0xFFFFF] === self::$rand) {
            if (\PHP_INT_SIZE >= 8 || 10 > \strlen($time = self::$time)) {
                $time = (string) (1 + $time);
            } elseif ('999999999' === $mtime = substr($time, -9)) {
                $time = (1 + substr($time, 0, -9)) . '000000000';
            } else {
                $time = substr_replace($time, str_pad(++$mtime, 9, '0', \STR_PAD_LEFT), -9);
            }

            goto randomize;
        } else {
            for ($i = 4; $i > 0 && 0xFFFFF === self::$rand[$i]; $i--) {
                self::$rand[$i] = 0;
            }

            self::$rand[$i]++;
            $time = self::$time;
        }

        if (\PHP_INT_SIZE >= 8) {
            $time = base_convert($time, 10, 32);
        } else {
            $time = str_pad(bin2hex(self::fromBase($time, self::BASE10)), 12, '0', \STR_PAD_LEFT);
            $time = sprintf(
                '%s%04s%04s',
                base_convert(substr($time, 0, 2), 16, 32),
                base_convert(substr($time, 2, 5), 16, 32),
                base_convert(substr($time, 7, 5), 16, 32)
            );
        }

        return strtr(sprintf(
            '%010s%04s%04s%04s%04s',
            $time,
            base_convert(self::$rand[1], 10, 32),
            base_convert(self::$rand[2], 10, 32),
            base_convert(self::$rand[3], 10, 32),
            base_convert(self::$rand[4], 10, 32)
        ), 'abcdefghijklmnopqrstuv', 'ABCDEFGHJKMNPQRSTVWXYZ');
    }
}
