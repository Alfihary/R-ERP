<?php

declare(strict_types=1);

namespace App\Domain\Vcards;

final class VcardQrService
{
    private const VERSION = 5;
    private const SIZE = 37;
    private const DATA_CODEWORDS = 108;
    private const ERROR_CODEWORDS = 26;

    /**
     * @return array{png: string, payload: string}
     */
    public function generate(string $payload, int $scale = 6, int $border = 4): array
    {
        $payload = trim($payload);

        if ($payload === '' || preg_match('/[\r\n]/', $payload) === 1) {
            throw new \InvalidArgumentException('QR payload is invalid.');
        }

        $bytes = array_values(unpack('C*', $payload) ?: []);

        if (count($bytes) > 106) {
            throw new \InvalidArgumentException('QR payload exceeds version 5-L capacity.');
        }

        $modules = $this->buildMatrix($bytes);

        return [
            'png' => $this->renderPng($modules, $scale, $border),
            'payload' => $payload,
        ];
    }

    private function buildMatrix(array $bytes): array
    {
        $modules = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));
        $function = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));

        $this->drawFunctionPatterns($modules, $function);
        $data = $this->dataCodewords($bytes);
        $codewords = array_merge($data, $this->reedSolomon($data, self::ERROR_CODEWORDS));
        $bits = [];

        foreach ($codewords as $codeword) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (($codeword >> $i) & 1) === 1;
            }
        }

        $this->drawCodewords($modules, $function, $bits);
        $this->applyMask($modules, $function);
        $this->drawFormatBits($modules, $function);

        return $modules;
    }

    private function drawFunctionPatterns(array &$modules, array &$function): void
    {
        $this->drawFinder($modules, $function, 3, 3);
        $this->drawFinder($modules, $function, self::SIZE - 4, 3);
        $this->drawFinder($modules, $function, 3, self::SIZE - 4);

        for ($i = 0; $i < self::SIZE; $i++) {
            $this->setFunction($modules, $function, 6, $i, $i % 2 === 0);
            $this->setFunction($modules, $function, $i, 6, $i % 2 === 0);
        }

        $this->drawAlignment($modules, $function, 30, 30);
        $this->setFunction($modules, $function, 8, 4 * self::VERSION + 9, true);

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($modules, $function, 8, $i, false);
            $this->setFunction($modules, $function, $i, 8, false);
            $this->setFunction($modules, $function, self::SIZE - 1 - $i, 8, false);
            $this->setFunction($modules, $function, 8, self::SIZE - 1 - $i, false);
        }
    }

    private function drawFinder(array &$modules, array &$function, int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;

                if ($x < 0 || $y < 0 || $x >= self::SIZE || $y >= self::SIZE) {
                    continue;
                }

                $distance = max(abs($dx), abs($dy));
                $this->setFunction(
                    $modules,
                    $function,
                    $x,
                    $y,
                    $distance !== 2 && $distance !== 4
                );
            }
        }
    }

    private function drawAlignment(array &$modules, array &$function, int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $this->setFunction(
                    $modules,
                    $function,
                    $cx + $dx,
                    $cy + $dy,
                    $distance !== 1
                );
            }
        }
    }

    private function drawFormatBits(array &$modules, array &$function): void
    {
        $bits = $this->formatBits();

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction($modules, $function, 8, $i, $this->bit($bits, $i));
        }

        $this->setFunction($modules, $function, 8, 7, $this->bit($bits, 6));
        $this->setFunction($modules, $function, 8, 8, $this->bit($bits, 7));
        $this->setFunction($modules, $function, 7, 8, $this->bit($bits, 8));

        for ($i = 9; $i < 15; $i++) {
            $this->setFunction($modules, $function, 14 - $i, 8, $this->bit($bits, $i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction(
                $modules,
                $function,
                self::SIZE - 1 - $i,
                8,
                $this->bit($bits, $i)
            );
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(
                $modules,
                $function,
                8,
                self::SIZE - 15 + $i,
                $this->bit($bits, $i)
            );
        }
    }

    private function dataCodewords(array $bytes): array
    {
        $buffer = new class {
            /** @var list<bool> */
            public array $bits = [];

            public function append(int $value, int $length): void
            {
                for ($i = $length - 1; $i >= 0; $i--) {
                    $this->bits[] = (($value >> $i) & 1) === 1;
                }
            }
        };
        $buffer->append(0b0100, 4);
        $buffer->append(count($bytes), 8);

        foreach ($bytes as $byte) {
            $buffer->append($byte, 8);
        }

        $capacityBits = self::DATA_CODEWORDS * 8;
        $buffer->append(0, min(4, $capacityBits - count($buffer->bits)));

        while (count($buffer->bits) % 8 !== 0) {
            $buffer->bits[] = false;
        }

        $codewords = [];

        foreach (array_chunk($buffer->bits, 8) as $chunk) {
            $value = 0;

            foreach ($chunk as $bit) {
                $value = ($value << 1) | ($bit ? 1 : 0);
            }

            $codewords[] = $value;
        }

        for ($pad = 0; count($codewords) < self::DATA_CODEWORDS; $pad++) {
            $codewords[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        return $codewords;
    }

    private function drawCodewords(array &$modules, array $function, array $bits): void
    {
        $i = 0;

        for ($right = self::SIZE - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vert = 0; $vert < self::SIZE; $vert++) {
                $y = (($right + 1) & 2) === 0 ? self::SIZE - 1 - $vert : $vert;

                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;

                    if ($function[$y][$x]) {
                        continue;
                    }

                    $modules[$y][$x] = $bits[$i] ?? false;
                    $i++;
                }
            }
        }
    }

    private function applyMask(array &$modules, array $function): void
    {
        for ($y = 0; $y < self::SIZE; $y++) {
            for ($x = 0; $x < self::SIZE; $x++) {
                if (!$function[$y][$x] && (($x + $y) % 2 === 0)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    private function reedSolomon(array $data, int $degree): array
    {
        $generator = [1];

        for ($i = 0; $i < $degree; $i++) {
            $generator = $this->polyMultiply($generator, [1, $this->gfPow(2, $i)]);
        }

        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;

            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= $this->gfMultiply($generator[$i + 1], $factor);
            }
        }

        return $result;
    }

    private function polyMultiply(array $a, array $b): array
    {
        $result = array_fill(0, count($a) + count($b) - 1, 0);

        foreach ($a as $i => $av) {
            foreach ($b as $j => $bv) {
                $result[$i + $j] ^= $this->gfMultiply($av, $bv);
            }
        }

        return $result;
    }

    private function gfMultiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    private function gfPow(int $x, int $power): int
    {
        $result = 1;

        for ($i = 0; $i < $power; $i++) {
            $result = $this->gfMultiply($result, $x);
        }

        return $result;
    }

    private function formatBits(): int
    {
        $data = 0b01000; // EC level L and mask pattern 0.
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        return (($data << 10) | $remainder) ^ 0x5412;
    }

    private function bit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) === 1;
    }

    private function setFunction(
        array &$modules,
        array &$function,
        int $x,
        int $y,
        bool $dark
    ): void {
        $modules[$y][$x] = $dark;
        $function[$y][$x] = true;
    }

    private function renderPng(array $modules, int $scale, int $border): string
    {
        $scale = max(1, min(20, $scale));
        $border = max(0, min(20, $border));
        $moduleCount = count($modules);
        $size = ($moduleCount + ($border * 2)) * $scale;
        $raw = '';

        for ($y = 0; $y < $size; $y++) {
            $raw .= "\x00";
            $moduleY = intdiv($y, $scale) - $border;

            for ($x = 0; $x < $size; $x++) {
                $moduleX = intdiv($x, $scale) - $border;
                $dark = $moduleX >= 0
                    && $moduleY >= 0
                    && $moduleX < $moduleCount
                    && $moduleY < $moduleCount
                    && $modules[$moduleY][$moduleX];
                $raw .= $dark ? "\x00\x00\x00" : "\xFF\xFF\xFF";
            }
        }

        return "\x89PNG\r\n\x1A\n"
            . $this->chunk('IHDR', pack('NNC5', $size, $size, 8, 2, 0, 0, 0))
            . $this->chunk('IDAT', zlib_encode($raw, ZLIB_ENCODING_DEFLATE))
            . $this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }
}
