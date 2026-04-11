<?php
/**
 * WingCoach — API Configuration (EXAMPLE)
 * Copy to config.php / config.staging.php / config.production-coaching.php
 * and fill in real credentials.
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');

// Stripe
define('STRIPE_SECRET_KEY', 'sk_live_...');
define('STRIPE_WEBHOOK_SECRET', 'whsec_...');

// Admin
define('ADMIN_PASSWORD', 'change_me');
define('DEV_BYPASS', false);

// Email
define('SMTP_HOST', 'mail.example.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'you@example.com');
define('SMTP_PASS', 'your_smtp_password');
define('NOTIFY_EMAIL', 'you@example.com');

// URLs
define('BASE_URL', 'https://your-domain.com');

// Upload directory
define('UPLOADS_DIR', __DIR__ . '/../uploads');

/**
 * Get PDO database connection (singleton)
 */
function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function setApiHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requireAdmin(): void {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth || !str_starts_with($auth, 'Basic ')) {
        header('WWW-Authenticate: Basic realm="WingCoach Admin"');
        http_response_code(401);
        echo 'Authentication required';
        exit;
    }
    $decoded = base64_decode(substr($auth, 6));
    $colonPos = strpos($decoded, ':');
    $pass = substr($decoded, $colonPos + 1);
    if ($pass !== ADMIN_PASSWORD) {
        header('WWW-Authenticate: Basic realm="WingCoach Admin"');
        http_response_code(401);
        echo 'Invalid credentials';
        exit;
    }
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function getRawBody(): string {
    return file_get_contents('php://input');
}

function safeName(string $original): string {
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $base = pathinfo($original, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base);
    return time() . '-' . $base . ($ext ? '.' . $ext : '');
}
