<?php
/**
 * Direct play links for a Vimeo-hosted reply video, so the reply page plays it in our own
 * player instead of Vimeo's embed (no Vimeo logo, no share/like/embed buttons).
 *
 * Vimeo signs these links and they expire after about a day, so they are never stored with
 * the reply item: the page asks for fresh ones on every load, cached here for an hour.
 * `$fresh` skips the cache - the page asks for that when a link it holds has died.
 */
const VIMEO_PLAY_CACHE_TTL = 3600;

function vimeoPlayLinks(string $videoId, bool $fresh = false): ?array {
    if (!defined('VIMEO_TOKEN') || !VIMEO_TOKEN || !ctype_digit($videoId)) return null;

    $cacheFile = sys_get_temp_dir() . '/wingcoach-vimeo-play-' . $videoId . '.json';
    if (!$fresh && is_file($cacheFile) && time() - filemtime($cacheFile) < VIMEO_PLAY_CACHE_TTL) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $fields = 'play,pictures.sizes,width,height,duration'; // 'play.hls.link' next to 'play.progressive' drops the MP4 list
    $ch = curl_init('https://api.vimeo.com/videos/' . $videoId . '?fields=' . $fields);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . VIMEO_TOKEN,
            'Accept: application/vnd.vimeo.*+json;version=3.4',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) {
        error_log("vimeoPlayLinks($videoId): Vimeo answered $code");
        return null;
    }
    $v = json_decode($raw, true) ?: [];
    if (($v['play']['status'] ?? '') !== 'playable') return null;

    $mp4 = [];
    foreach ($v['play']['progressive'] ?? [] as $p) {
        if (empty($p['link'])) continue;
        $mp4[] = ['src' => $p['link'], 'height' => (int) ($p['height'] ?? 0)];
    }
    usort($mp4, fn($a, $b) => $b['height'] <=> $a['height']);

    // Largest thumbnail no wider than 1920 px.
    $poster = null;
    foreach ($v['pictures']['sizes'] ?? [] as $s) {
        if (($s['width'] ?? 0) <= 1920) $poster = $s['link'] ?? $poster;
    }

    $out = [
        'hls'      => $v['play']['hls']['link'] ?? null,
        'mp4'      => $mp4,
        'poster'   => $poster,
        'width'    => (int) ($v['width'] ?? 0),
        'height'   => (int) ($v['height'] ?? 0),
        'duration' => (int) ($v['duration'] ?? 0),
    ];
    if (!$out['hls'] && !$mp4) return null;

    @file_put_contents($cacheFile, json_encode($out), LOCK_EX);
    return $out;
}
