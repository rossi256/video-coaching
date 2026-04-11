<?php
define('BASE_PATH', getenv('WINGCOACH_BASE_PATH') ?: '/video-coaching');

// Basic auth
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$auth || !str_starts_with($auth, 'Basic ')) {
    header('WWW-Authenticate: Basic realm="WingCoach Admin"');
    http_response_code(401);
    echo 'Authentication required';
    exit;
}
$decoded = base64_decode(substr($auth, 6));
$pass = substr($decoded, strpos($decoded, ':') + 1);
if ($pass !== '!Solution123') {
    header('WWW-Authenticate: Basic realm="WingCoach Admin"');
    http_response_code(401);
    echo 'Invalid credentials';
    exit;
}

$html = file_get_contents(__DIR__ . '/static/admin.html');
$html = str_replace('{{BASE_PATH}}', BASE_PATH, $html);
header('Content-Type: text/html; charset=utf-8');
echo $html;
