<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Comprimeert videobestanden naar een doelgrootte met ffmpeg.
 *
 * Aanleiding: Publer's media-upload endpoint weigert grote bestanden met
 * HTTP 413 (in de praktijk ergens boven ~100 MB). Instagram is bovendien
 * strenger dan Facebook/LinkedIn. Door vooraf te comprimeren naar ~50 MB
 * kan élke video naar álle netwerken.
 */
class VideoCompressionService
{
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'wmv', 'webm', 'm4v'];

    /** Grens waarboven we comprimeren (bytes). */
    public const THRESHOLD_BYTES = 100 * 1024 * 1024; // 100 MB

    /** Doelgrootte na compressie (bytes). */
    public const TARGET_BYTES = 50 * 1024 * 1024; // 50 MB

    public static function isVideo(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true);
    }

    /**
     * Comprimeer het bestand in-place (vervangt het origineel door een .mp4)
     * als het een video boven de drempel is. Retourneert het (eventueel
     * nieuwe) relatieve pad, of null bij falen.
     *
     * @param string $relativePath pad relatief aan storage/app/public
     */
    public function compressIfNeeded(string $relativePath): ?string
    {
        $absolute = storage_path('app/public/' . $relativePath);

        if (! file_exists($absolute) || ! self::isVideo($absolute)) {
            return $relativePath;
        }

        $size = filesize($absolute);
        if ($size <= self::THRESHOLD_BYTES) {
            return $relativePath;
        }

        $duration = $this->probeDuration($absolute);
        if ($duration === null || $duration < 1) {
            Log::warning('VideoCompression: kan duur niet bepalen, overslaan', ['path' => $relativePath]);
            return $relativePath;
        }

        // Bitrate-budget: doelgrootte verdelen over de duur, audio vast op 128k.
        $audioKbit = 128;
        $totalKbit = (int) floor((self::TARGET_BYTES * 8) / 1024 / $duration);
        $videoKbit = max(300, $totalKbit - $audioKbit);

        $newRelative = preg_replace('/\.[^.]+$/', '', $relativePath) . '-compressed.mp4';
        $newAbsolute = storage_path('app/public/' . $newRelative);

        Log::info('VideoCompression: start', [
            'path'        => $relativePath,
            'size_mb'     => round($size / 1048576, 1),
            'duration_s'  => round($duration),
            'video_kbit'  => $videoKbit,
        ]);

        $result = Process::timeout(1600)->run([
            'ffmpeg', '-y', '-i', $absolute,
            '-c:v', 'libx264', '-preset', 'fast',
            '-b:v', "{$videoKbit}k", '-maxrate', "{$videoKbit}k", '-bufsize', (2 * $videoKbit) . 'k',
            '-vf', "scale='min(1920,iw)':-2",
            '-c:a', 'aac', '-b:a', "{$audioKbit}k",
            '-movflags', '+faststart',
            $newAbsolute,
        ]);

        if (! $result->successful() || ! file_exists($newAbsolute) || filesize($newAbsolute) === 0) {
            Log::error('VideoCompression: ffmpeg mislukt', [
                'path'   => $relativePath,
                'stderr' => substr($result->errorOutput(), -500),
            ]);
            @unlink($newAbsolute);
            return null;
        }

        // Origineel vervangen door de gecomprimeerde versie.
        @unlink($absolute);

        Log::info('VideoCompression: klaar', [
            'path'        => $newRelative,
            'new_size_mb' => round(filesize($newAbsolute) / 1048576, 1),
        ]);

        return $newRelative;
    }

    private function probeDuration(string $absolute): ?float
    {
        $result = Process::timeout(60)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolute,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $duration = (float) trim($result->output());

        return $duration > 0 ? $duration : null;
    }
}
