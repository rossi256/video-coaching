<?php
define('BASE_PATH', getenv('WINGCOACH_BASE_PATH') ?: '/video-coaching');
$html = file_get_contents(__DIR__ . '/static/reply.html');
$html = str_replace('{{BASE_PATH}}', BASE_PATH, $html);
header('Content-Type: text/html; charset=utf-8');
echo $html;
