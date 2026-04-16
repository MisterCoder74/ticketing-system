<?php
// inc/auth.php — Authentication, sessions, permissions

define('APP_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
define('APP_URL', (function (): string {
    // Derive the app base URL from SCRIPT_NAME + SCRIPT_FILENAME.
    // This works on any server regardless of symlinks or DOCUMENT_ROOT quirks:
    //   SCRIPT_FILENAME (realpath) = APP_ROOT + /relative/path/to/script.php
    //   SCRIPT_NAME                = /url/base  + /relative/path/to/script.php
    // So: APP_URL = SCRIPT_NAME minus the relative suffix.
    $appRoot    = rtrim(APP_ROOT, '/') . '/';
    $scriptReal = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $relScript  = ltrim(str_replace($appRoot, '', $scriptReal), '/');   // e.g. "index.php" or "inc/api.php"
    $scriptUrl  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''); // e.g. "/TESTVARI/.../index.php"
    if ($relScript !== '' && str_ends_with($scriptUrl, '/' . $relScript)) {
        return rtrim(substr($scriptUrl, 0, strlen($scriptUrl) - strlen($relScript) - 1), '/');
    }
    // Fallback: strip realpath-resolved DOCUMENT_ROOT
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    return rtrim(str_replace($docRoot, '', rtrim(str_replace('\\', '/', APP_ROOT), '/')), '/');
})());

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── App settings ──────────────────────────────────────────────────────────────

function appSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $file  = APP_ROOT . '/data/settings.json';
    $saved = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    $cache = array_merge([
        'brand_name'          => 'Ticketing System',
        'brand_logo'          => '',
        'brand_color'         => '#0d6efd',
        'support_email'       => '',
        'email_notifications' => false,
        'smtp_host'           => '',
        'smtp_port'           => 587,
        'smtp_user'           => '',
        'smtp_pass'           => '',
        'smtp_from'           => '',
        'smtp_from_name'      => 'Ticketing System',
        'smtp_encryption'     => 'tls',
    ], $saved);
    return $cache;
}

// ── JSON helpers ─────────────────────────────────────────────────────────────

function loadJson(string $file): array {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}

function saveJson(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(
        $file,
        json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

// ── Auth ──────────────────────────────────────────────────────────────────────

function login(string $username, string $password): ?array {
    $users = loadJson(APP_ROOT . '/data/users.json');
    foreach ($users as $u) {
        if ($u['username'] === $username
            && $u['password'] === $password
            && ($u['active'] ?? true)
        ) {
            return $u;
        }
    }
    return null;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user']);
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        if (isApiRequest()) {
            jsonResponse(['error' => 'Non autenticato'], 401);
        }
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        isApiRequest()
            ? jsonResponse(['error' => 'Permesso negato'], 403)
            : http_response_code(403) && die('Accesso negato.');
    }
}

function hasRole(string ...$roles): bool {
    if (!isLoggedIn()) return false;
    return in_array(currentUser()['role'], $roles, true);
}

function isAdmin(): bool    { return hasRole('admin'); }
function isOperator(): bool { return hasRole('operator', 'admin'); }

function isApiRequest(): bool {
    return str_contains($_SERVER['REQUEST_URI'] ?? '', '/inc/api.php')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function jsonResponse(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
