<?php

namespace App\Icons;

use App\Ai\Exceptions\NotAnImage;

/** Makes the stored icon: a 256 px WebP (GD), or the PNG as received when this PHP has no GD with WebP. */
class IconImage
{
    /** @return array{bytes: string, ext: string, width: int, height: int} */
    public function make(string $png): array
    {
        // Bytes that are not an image must fail here (the job ends `failed`, the emoji stays), never be stored as a 0x0 file marked done.
        $info = @getimagesizefromstring($png);
        if ($info === false) {
            throw new NotAnImage('The provider returned bytes that are not an image.');
        }

        $px = (int) config('images.stored_px');

        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $source = @imagecreatefromstring($png);
            if ($source !== false) {
                $w = imagesx($source);
                $h = imagesy($source);
                $target = imagecreatetruecolor($px, $px);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
                imagecopyresampled($target, $source, 0, 0, 0, 0, $px, $px, $w, $h);

                ob_start();
                imagewebp($target, null, 85);
                $webp = (string) ob_get_clean();

                if ($webp !== '') {
                    return ['bytes' => $webp, 'ext' => 'webp', 'width' => $px, 'height' => $px];
                }
            }
        }

        return ['bytes' => $png, 'ext' => 'png', 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }
}
