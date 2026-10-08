<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

final class CredentialQrService
{
    /** @return array{png: string, payload: string} */
    public function generate(string $payload, int $scale = 6, int $border = 4): array
    {
        $payload = trim($payload);

        if ($payload === '' || preg_match('/[\r\n]/', $payload) === 1) {
            throw new \InvalidArgumentException('QR payload is invalid.');
        }

        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::H())->getMatrix();
        $scale = max(1, min(20, $scale));
        $border = max(2, min(20, $border));
        $moduleCount = $matrix->getWidth();
        $size = ($moduleCount + 2 * $border) * $scale;
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new \RuntimeException('Could not render credential QR.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $blue = imagecolorallocate($image, 22, 64, 104);
        $teal = imagecolorallocate($image, 14, 122, 127);
        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $moduleCount; $y++) {
            for ($x = 0; $x < $moduleCount; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }

                $color = $black;
                if ($this->isFinderOuterRing($x, $y, 0, 0)
                    || $this->isFinderOuterRing($x, $y, 0, $moduleCount - 7)) {
                    $color = $blue;
                } elseif ($this->isFinderOuterRing($x, $y, $moduleCount - 7, 0)) {
                    $color = $teal;
                }

                $left = ($x + $border) * $scale;
                $top = ($y + $border) * $scale;
                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $color);
            }
        }

        $this->placeLogo($image, $moduleCount, $scale, $border, $white);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        if (!is_string($png)) {
            throw new \RuntimeException('Could not encode credential QR.');
        }

        return ['png' => $png, 'payload' => $payload];
    }

    private function isFinderOuterRing(int $x, int $y, int $left, int $top): bool
    {
        $relativeX = $x - $left;
        $relativeY = $y - $top;

        return $relativeX >= 0 && $relativeX < 7
            && $relativeY >= 0 && $relativeY < 7
            && ($relativeX === 0 || $relativeX === 6 || $relativeY === 0 || $relativeY === 6);
    }

    private function placeLogo(\GdImage $image, int $moduleCount, int $scale, int $border, int $white): void
    {
        $logoPath = dirname(__DIR__, 3) . '/public/img/gr.png';
        if (!is_file($logoPath)) {
            return;
        }

        $logo = @imagecreatefrompng($logoPath);
        if ($logo === false) {
            return;
        }

        // A compact white knockout keeps the mark legible without touching finder patterns.
        $badgeWidth = 9 * $scale;
        $badgeHeight = 7 * $scale;
        $center = ($border + $moduleCount / 2) * $scale;
        $left = (int) round($center - $badgeWidth / 2);
        $top = (int) round($center - $badgeHeight / 2);
        imagefilledrectangle($image, $left, $top, $left + $badgeWidth - 1, $top + $badgeHeight - 1, $white);

        $inset = max(1, (int) round($scale * .3));
        $minX = imagesx($logo);
        $minY = imagesy($logo);
        $maxX = 0;
        $maxY = 0;

        // The supplied square PNG has transparent space above and below the GR mark.
        for ($y = 0; $y < imagesy($logo); $y += 4) {
            for ($x = 0; $x < imagesx($logo); $x += 4) {
                if ((imagecolorat($logo, $x, $y) >> 24) < 120) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($minX > $maxX || $minY > $maxY) {
            imagedestroy($logo);
            return;
        }

        $sourceX = max(0, $minX - 4);
        $sourceY = max(0, $minY - 4);
        $sourceWidth = min(imagesx($logo) - $sourceX, $maxX - $sourceX + 8);
        $sourceHeight = min(imagesy($logo) - $sourceY, $maxY - $sourceY + 8);
        imagealphablending($image, true);
        imagecopyresampled(
            $image,
            $logo,
            $left + $inset,
            $top + $inset,
            $sourceX,
            $sourceY,
            $badgeWidth - 2 * $inset,
            $badgeHeight - 2 * $inset,
            $sourceWidth,
            $sourceHeight
        );
        imagedestroy($logo);
    }
}
