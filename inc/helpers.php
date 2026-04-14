<?php
// inc/helpers.php — Validation, sanitization, file upload

if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));

define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 MB

function sanitize(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

function sanitizeFields(array $data, array $fields): array {
    $out = [];
    foreach ($fields as $f) {
        if (isset($data[$f])) $out[$f] = sanitize((string)$data[$f]);
    }
    return $out;
}

function validateRequired(array $data, array $fields): array {
    $errors = [];
    foreach ($fields as $f) {
        if (empty($data[$f])) $errors[] = "Il campo '{$f}' è obbligatorio.";
    }
    return $errors;
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function generateId(string $prefix = ''): string {
    return $prefix . bin2hex(random_bytes(6));
}

function nowIso(): string {
    return (new DateTime())->format('Y-m-d\TH:i:s');
}

// ── File upload ───────────────────────────────────────────────────────────────

function handleUpload(array $file, string $ticketId): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > MAX_FILE_SIZE) return null;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) return null;

    $dir = APP_ROOT . '/uploads/' . $ticketId . '/uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = generateId('img_') . '.' . $ext;
    $dest = $dir . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;

    return $ticketId . '/uploads/' . $name;
}

function handleMultipleUploads(array $files, string $ticketId): array {
    $uploaded = [];
    if (!is_array($files['name'])) {
        $p = handleUpload($files, $ticketId);
        if ($p) $uploaded[] = $p;
        return $uploaded;
    }
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $p = handleUpload([
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ], $ticketId);
        if ($p) $uploaded[] = $p;
    }
    return $uploaded;
}

// ── Activity log ──────────────────────────────────────────────────────────────

function appendLog(string $action, string $userId, string $note): void {
    $file = APP_ROOT . '/data/activity_log.json';
    $logs = loadJson($file);
    $logs[] = [
        'id'      => generateId('log'),
        'action'  => $action,
        'user_id' => $userId,
        'note'    => $note,
        'at'      => nowIso(),
    ];
    if (count($logs) > 1000) $logs = array_slice($logs, -1000);
    saveJson($file, $logs);
}
