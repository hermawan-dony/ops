<?php
/**
 * Compresses and resizes an uploaded image file, saving it as JPEG.
 * Also generates a smaller thumbnail version.
 * 
 * @param string $sourcePath Path to the temp uploaded file
 * @param string $destPath Path to save the compressed main image
 * @param string $thumbDestPath Path to save the thumbnail image
 * @param int $maxWidth Max width for main image
 * @param int $maxHeight Max height for main image
 * @param int $thumbWidth Max width for thumbnail
 * @param int $thumbHeight Max height for thumbnail
 * @param int $quality Compression quality (0-100)
 * @return bool True if main image was successfully compressed, false otherwise.
 */
function compressAndResizeImage($sourcePath, $destPath, $thumbDestPath = null, $maxWidth = 1200, $maxHeight = 1200, $thumbWidth = 200, $thumbHeight = 200, $quality = 75) {
    if (!extension_loaded('gd')) {
        // Fallback: If GD is not loaded, just move the original file
        $copied = copy($sourcePath, $destPath);
        if ($copied && $thumbDestPath) {
            copy($sourcePath, $thumbDestPath); // Duplicate as fallback thumb
        }
        return $copied;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) {
        return copy($sourcePath, $destPath); // Fallback if image type not detected
    }

    list($width, $height, $type) = $imageInfo;

    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_GIF:
            $src = @imagecreatefromgif($sourcePath);
            break;
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $src = @imagecreatefromwebp($sourcePath);
            break;
        default:
            $src = false;
            break;
    }

    if (!$src) {
        return copy($sourcePath, $destPath); // Fallback
    }

    // Fix orientation if JPEG has EXIF data
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        if ($exif && isset($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $src = imagerotate($src, 180, 0);
                    break;
                case 6:
                    $src = imagerotate($src, -90, 0);
                    break;
                case 8:
                    $src = imagerotate($src, 90, 0);
                    break;
            }
            // Update dimensions after rotation
            $width = imagesx($src);
            $height = imagesy($src);
        }
    }

    // Helper closure to resize and save as JPEG
    $resizeAndSave = function($srcImg, $w, $h, $maxW, $maxH, $dest, $q) {
        $ratio = $w / $h;
        if ($w > $maxW || $h > $maxH) {
            if ($ratio > 1) {
                $newW = $maxW;
                $newH = round($maxW / $ratio);
            } else {
                $newH = $maxH;
                $newW = round($maxH * $ratio);
            }
        } else {
            $newW = $w;
            $newH = $h;
        }

        $dstImg = imagecreatetruecolor($newW, $newH);
        
        // Handle transparency if we decide to keep format, but here we output JPEG so solid background
        $white = imagecolorallocate($dstImg, 255, 255, 255);
        imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $white);

        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
        $saved = imagejpeg($dstImg, $dest, $q);
        imagedestroy($dstImg);
        return $saved;
    };

    // Save compressed main image
    $mainSuccess = $resizeAndSave($src, $width, $height, $maxWidth, $maxHeight, $destPath, $quality);

    // Save thumbnail if path provided
    if ($thumbDestPath) {
        $resizeAndSave($src, $width, $height, $thumbWidth, $thumbHeight, $thumbDestPath, $quality);
    }

    imagedestroy($src);
    return $mainSuccess;
}
