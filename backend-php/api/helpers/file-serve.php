<?php
/**
 * WingCoach — File serving with HTTP Range support (206 Partial Content)
 * Used for video streaming in reply and admin endpoints
 */

function serveFile(string $filePath): void {
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $size = filesize($filePath);
    $mime = mime_content_type($filePath) ?: 'application/octet-stream';

    // Force video mime types for common extensions
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $videoMimes = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
    ];
    if (isset($videoMimes[$ext])) {
        $mime = $videoMimes[$ext];
    }

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');

    // Handle Range request
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }

        $start = (int) $matches[1];
        $end = $matches[2] !== '' ? (int) $matches[2] : $size - 1;

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }

        $end = min($end, $size - 1);
        $length = $end - $start + 1;

        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: $length");

        $fp = fopen($filePath, 'rb');
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = min(8192, $remaining);
            echo fread($fp, $chunk);
            $remaining -= $chunk;
            flush();
        }
        fclose($fp);
    } else {
        header("Content-Length: $size");
        readfile($filePath);
    }
    exit;
}
