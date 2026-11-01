<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use GdImage;
use Illuminate\Http\UploadedFile;

final class DemoFiles
{
    private const string FALLBACK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function make(DemoFileType $type, string $name, string $caption): UploadedFile
    {
        $contents = match ($type) {
            DemoFileType::Screenshot => $this->png($caption),
            DemoFileType::Document => $this->pdf($caption),
            DemoFileType::Log => $this->log($caption),
            DemoFileType::Spreadsheet => $this->csv(),
        };

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    public function png(string $caption, int $width = 640, int $height = 400): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return (string) base64_decode(self::FALLBACK_PNG, true);
        }

        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof GdImage) {
            return (string) base64_decode(self::FALLBACK_PNG, true);
        }

        $background = (int) imagecolorallocate($image, 246, 247, 249);
        $chrome = (int) imagecolorallocate($image, 226, 229, 234);
        $text = (int) imagecolorallocate($image, 55, 65, 81);
        $muted = (int) imagecolorallocate($image, 209, 213, 219);
        $danger = (int) imagecolorallocate($image, 220, 38, 38);
        $white = (int) imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagefilledrectangle($image, 0, 0, $width, 36, $chrome);

        foreach ([[18, 239, 68, 68], [38, 245, 158, 11], [58, 34, 197, 94]] as [$x, $r, $g, $b]) {
            imagefilledellipse($image, $x, 18, 12, 12, (int) imagecolorallocate($image, $r, $g, $b));
        }

        imagefilledrectangle($image, 0, 36, 140, $height, $white);

        for ($row = 0; $row < 8; $row++) {
            imagefilledrectangle($image, 16, 60 + $row * 30, 120, 70 + $row * 30, $muted);
        }

        imagefilledrectangle($image, 170, 60, $width - 30, 110, $danger);
        imagestring($image, 5, 186, 77, mb_strimwidth($caption, 0, 50, '...'), $white);

        for ($row = 0; $row < 7; $row++) {
            $lineWidth = $width - 60 - ($row * 37) % 180;
            imagefilledrectangle($image, 170, 140 + $row * 30, $lineWidth, 152 + $row * 30, $muted);
        }

        imagestring($image, 3, 170, $height - 30, 'Captured '.CarbonImmutable::now()->format('Y-m-d H:i'), $text);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function pdf(string $title): string
    {
        $lines = [
            $title,
            '',
            'Generated on '.CarbonImmutable::now()->format('j F Y'),
            'Reference: DOC-'.CarbonImmutable::now()->format('ymd').'-'.mt_rand(100, 999),
            '',
            'This document was attached to a support ticket.',
            'Please contact support if anything is unclear.',
        ];

        $stream = "BT\n/F1 12 Tf\n72 760 Td\n16 TL\n";

        foreach ($lines as $line) {
            $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).") Tj T*\n";
        }

        $stream .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n"."0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }

    private function log(string $caption): string
    {
        $moment = CarbonImmutable::now()->subMinutes(mt_rand(20, 90));
        $levels = ['INFO', 'INFO', 'WARNING', 'ERROR'];
        $messages = [
            'Request started GET /api/v2/projects',
            'Upstream responded in %d ms',
            'Retrying request (attempt %d of 3)',
            'Request failed: '.$caption,
            'Connection reset by peer after %d ms',
            'Worker picked up job export:%d',
        ];

        $lines = [];

        for ($i = 0; $i < 12; $i++) {
            $moment = $moment->addSeconds(mt_rand(1, 40));
            $lines[] = sprintf(
                '[%s] %s request_id=%s %s',
                $moment->format('Y-m-d H:i:s'),
                $levels[$i % count($levels)],
                substr(md5((string) mt_rand()), 0, 12),
                sprintf($messages[$i % count($messages)], mt_rand(80, 9000)),
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function csv(): string
    {
        $rows = ['reference,description,quantity,amount,date'];
        $items = ['Seat licence', 'Storage add-on', 'Priority support', 'API package', 'Onboarding session'];
        $day = CarbonImmutable::now()->subDays(30);

        foreach ($items as $index => $item) {
            $rows[] = sprintf('REF-%04d,%s,%d,%.2f,%s', 1200 + $index * 7, $item, mt_rand(1, 25), mt_rand(900, 45000) / 100, $day->addDays($index * 5)->format('Y-m-d'));
        }

        return implode("\n", $rows)."\n";
    }
}
