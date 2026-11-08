<?php

namespace App\Services\Media;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploaded images after scaling them down and re-encoding them, so callers never have to
 * reject a file for being too large. Non-raster uploads (ICO favicons) are stored untouched.
 */
class ImageCompressor
{
    /**
     * @param  array{max_width?: int, max_height?: int, quality?: int, max_bytes?: int}  $options
     */
    public function store(UploadedFile $file, string $directory, string $disk = 'public', array $options = []): string
    {
        $maxWidth = $options['max_width'] ?? 1024;
        $maxHeight = $options['max_height'] ?? 1024;
        $quality = $options['quality'] ?? 82;
        $maxBytes = $options['max_bytes'] ?? 512 * 1024;

        $path = $file->getRealPath();
        $info = $path ? @getimagesize($path) : false;
        $image = $info !== false ? $this->decode($path) : null;

        if (! $image instanceof GdImage) {
            return $file->store($directory, $disk);
        }

        $image = $this->orient($image, $path, (int) $info[2]);
        $image = $this->scale($image, $maxWidth, $maxHeight);

        $format = $this->hasTransparency($image) ? 'png' : 'jpeg';
        $bytes = $this->encode($image, $format, $quality, $maxBytes);

        $name = Str::random(40).'.'.($format === 'jpeg' ? 'jpg' : 'png');
        $target = trim($directory, '/').'/'.$name;
        Storage::disk($disk)->put($target, $bytes);

        imagedestroy($image);

        return $target;
    }

    /** @param  array{max_width?: int, max_height?: int, quality?: int, max_bytes?: int}  $options */
    public function storeAsFavicon(UploadedFile $file, string $directory, string $disk = 'public', array $options = []): string
    {
        return $this->store($file, $directory, $disk, $options + ['max_width' => 256, 'max_height' => 256, 'max_bytes' => 128 * 1024]);
    }

    private function decode(string $path): ?GdImage
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $image = @imagecreatefromstring($contents);

        return $image instanceof GdImage ? $image : null;
    }

    private function scale(GdImage $image, int $maxWidth, int $maxHeight): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $factor = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));

        if ($factor >= 1) {
            return $image;
        }

        $newWidth = max(1, (int) round($width * $factor));
        $newHeight = max(1, (int) round($height * $factor));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    private function orient(GdImage $image, string $path, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 0)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof GdImage) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

    /** Probe a small grid of pixels rather than the whole image. */
    private function hasTransparency(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) floor($width / 24));
        $stepY = max(1, (int) floor($height / 24));

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function encode(GdImage $image, string $format, int $quality, int $maxBytes): string
    {
        if ($format === 'png') {
            ob_start();
            imagepng($image, null, 9);
            $bytes = (string) ob_get_clean();

            return $bytes;
        }

        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, imagesx($image), imagesy($image), $white);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        $bytes = '';
        for ($q = $quality; $q >= 35; $q -= 7) {
            ob_start();
            imagejpeg($flat, null, $q);
            $bytes = (string) ob_get_clean();
            if (strlen($bytes) <= $maxBytes) {
                break;
            }
        }
        imagedestroy($flat);

        return $bytes;
    }
}
