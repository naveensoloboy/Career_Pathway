<?php
// config/config.php
// Core configuration: DB connection, session, constants, helpers

declare(strict_types=1);

$envBase = getenv('BASE_URL') ?: ($_ENV['BASE_URL'] ?? '');
if ($envBase) {
    if (!defined('BASE_URL')) define('BASE_URL', rtrim($envBase, '/'));
} else {
    if (!defined('BASE_URL')) {
        // prefer forwarded headers if set (useful behind reverse proxies)
        $proto = null;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $proto = 'https';
        } else {
            $proto = 'http';
        }

        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        $publicPos = strpos($script, '/public');
        if ($publicPos !== false) {
            $path = substr($script, 0, $publicPos);
            if ($path === '') $path = '/';
        } else {
            $path = rtrim(dirname($script), '/');
            if ($path === '') $path = '/';
            $commonSubs = ['auth','admin','attendee','club','includes','public'];
            $baseSegment = basename($path);
            if (in_array($baseSegment, $commonSubs, true)) {
                $parent = dirname($path);
                if ($parent !== '/' && $parent !== '.' && $parent !== '') {
                    $path = rtrim($parent, '/');
                } else {
                    $path = '/';
                }
            }
        }

        $base = rtrim($proto . '://' . $host . ($path === '/' ? '' : $path), '/');
        define('BASE_URL', $base);
    }
}

// Helper PUBLIC_URL
if (!defined('PUBLIC_URL')) define('PUBLIC_URL', rtrim(BASE_URL, '/') . '/public');

////////////////////////////////////
// TIMEZONE
////////////////////////////////////
date_default_timezone_set('Asia/Kolkata');

// ---------------------------
// DATABASE (env-friendly)
// ---------------------------
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'mcq_app';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbCharset = getenv('DB_CHARSET') ?: 'utf8mb4';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $pdoOptions);
} catch (PDOException $e) {
    // during development you may want the real message, but avoid in production
    if (getenv('APP_ENV') === 'development') {
        echo "Database connection failed: " . e($e->getMessage());
    } else {
        echo "Database connection failed. Check config.php settings.";
    }
    exit;
}


////////////////////////////////////
// SESSION (SECURE STYLE)
////////////////////////////////////
if (session_status() === PHP_SESSION_NONE) {
    $cookie = session_get_cookie_params();

    // domain: leave empty to use current host, avoids cross-host issues
    $domain = $cookie['domain'] ?? '';

    session_set_cookie_params([
        'lifetime' => $cookie['lifetime'] ?? 0,
        'path' => $cookie['path'] ?? '/',
        'domain' => $domain,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), // true only with HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

////////////////////////////////////
// FLASH MESSAGE HELPERS
////////////////////////////////////
if (!function_exists('flash_set')) {
    function flash_set(string $key, string $message): void {
        $_SESSION['flash'][$key] = $message;
    }
}
if (!function_exists('flash_get')) {
    function flash_get(string $key): ?string {
        if (isset($_SESSION['flash'][$key])) {
            $msg = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $msg;
        }
        return null;
    }
}

////////////////////////////////////
// CSRF HELPERS
////////////////////////////////////
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('validate_csrf')) {
    function validate_csrf(string $token): bool {
        return !empty($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

////////////////////////////////////
// AUTH HELPERS
////////////////////////////////////
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return isset($_SESSION['user_roll']);
    }
}
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!is_logged_in()) {
            $_SESSION['after_login_redirect'] = $_SERVER['REQUEST_URI'] ?? '/';
            header("Location: " . BASE_URL . "/auth/login.php");
            exit;
        }
    }
}
if (!function_exists('current_user')) {
    function current_user(PDO $pdo): ?array {
        if (!is_logged_in()) return null;

        if (isset($_SESSION['user_cache'])) {
            return $_SESSION['user_cache'];
        }

        $stmt = $pdo->prepare(
            "SELECT roll_no, email, full_name, role, class, is_active 
             FROM users WHERE roll_no = :r LIMIT 1"
        );
        $stmt->execute([':r' => $_SESSION['user_roll']]);
        $row = $stmt->fetch();

        if ($row) {
            $_SESSION['user_cache'] = $row;
            return $row;
        }

        return null;
    }
}
if (!function_exists('is_admin')) {
    function is_admin(PDO $pdo): bool {
        $u = current_user($pdo);
        return (bool) ($u && ($u['role'] ?? '') === 'admin');
    }
}
if (!function_exists('require_admin')) {
    function require_admin(PDO $pdo): void {
        require_login();
        if (!is_admin($pdo)) {
            http_response_code(403);
            echo "Access denied. Admin only.";
            exit;
        }
    }
}
if (!function_exists('get_club_role')) {
    function get_club_role(PDO $pdo, string $roll, int $club_id): ?array {
        $stmt = $pdo->prepare(
            "SELECT role, can_post_questions 
             FROM club_roles 
             WHERE club_id = :cid AND user_roll = :r LIMIT 1"
        );
        $stmt->execute([':cid' => $club_id, ':r' => $roll]);
        return $stmt->fetch() ?: null;
    }
}
if (!function_exists('require_club_role')) {
    function require_club_role(PDO $pdo, int $club_id, array $allowedRoles): void {
        require_login();
        $info = get_club_role($pdo, $_SESSION['user_roll'], $club_id);

        if (!$info || !in_array($info['role'], $allowedRoles, true)) {
            http_response_code(403);
            echo "Access denied. Required club role not found.";
            exit;
        }
    }
}

////////////////////////////////////
// UTILITY HELPERS
////////////////////////////////////
if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('redirect')) {
    function redirect(string $url): void {
        header("Location: {$url}");
        exit;
    }
}
