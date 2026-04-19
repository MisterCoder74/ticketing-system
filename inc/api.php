<?php
// inc/api.php — REST-like backend API

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

header('Cache-Control: no-store');
// CSV exports send their own headers — skip JSON content-type for those actions
$_exportActions = ['export_tickets', 'export_logs', 'export_report'];
if (!in_array($_REQUEST['action'] ?? '', $_exportActions, true)) {
    header('Content-Type: application/json; charset=utf-8');
}

$action = $_REQUEST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Public routes (no auth needed)
$public = ['login'];

if (!in_array($action, $public, true)) {
    requireLogin();
}

$user = currentUser();

// ── Router ────────────────────────────────────────────────────────────────────
switch ($action) {

    // Auth
    case 'login':           apiLogin();          break;
    case 'logout':          apiLogout();         break;
    case 'me':              apiMe();             break;

    // Tickets
    case 'tickets':         apiGetTickets();     break;
    case 'ticket':          apiGetTicket();      break;
    case 'create_ticket':   apiCreateTicket();   break;
    case 'update_ticket':
        requireRole('operator', 'admin');
        apiUpdateTicket();
        break;
    case 'delete_ticket':
        requireRole('admin');
        apiDeleteTicket();
        break;

    // Comments
    case 'comments':        apiGetComments();    break;
    case 'add_comment':     apiAddComment();     break;

    // Users
    case 'users':
        requireRole('admin');
        apiGetUsers();
        break;
    case 'create_user':
        requireRole('admin');
        apiCreateUser();
        break;
    case 'update_user':
        requireRole('admin');
        apiUpdateUser();
        break;
    case 'delete_user':
        requireRole('admin');
        apiDeleteUser();
        break;

    // Upload
    case 'upload':          apiUpload();         break;

    // Operators list
    case 'operators':
        requireRole('operator', 'admin');
        apiGetOperators();
        break;

    // Notifications
    case 'notifications':   apiNotifications();  break;

    // Admin only
    case 'report':
        requireRole('admin');
        apiReport();
        break;
    case 'logs':
        requireRole('admin');
        apiLogs();
        break;

    // Settings
    case 'get_settings':
        requireRole('admin');
        apiGetSettings();
        break;
    case 'save_settings':
        requireRole('admin');
        apiSaveSettings();
        break;

    // CSV exports
    case 'export_tickets':
        requireRole('admin');
        apiExportTickets();
        break;
    case 'export_logs':
        requireRole('admin');
        apiExportLogs();
        break;
    case 'export_report':
        requireRole('admin');
        apiExportReport();
        break;

    // Stale ticket check
    case 'stale_check':
        requireRole('admin');
        apiStaleCheck();
        break;

    default:
        jsonResponse(['error' => 'Azione non valida'], 400);
}

// ── AUTH ──────────────────────────────────────────────────────────────────────

function apiLogin(): void {
    $d = jsonBody();
    $u = trim($d['username'] ?? '');
    $p = trim($d['password'] ?? '');
    if (!$u || !$p) jsonResponse(['error' => 'Credenziali mancanti'], 400);

    $user = login($u, $p);
    if (!$user) jsonResponse(['error' => 'Credenziali non valide'], 401);

    $_SESSION['user'] = $user;
    $safe = $user; unset($safe['password']);
    jsonResponse(['success' => true, 'user' => $safe]);
}

function apiLogout(): void {
    logout();
    jsonResponse(['success' => true]);
}

function apiMe(): void {
    global $user;
    $safe = $user; unset($safe['password']);
    jsonResponse(['user' => $safe]);
}

// ── TICKETS ───────────────────────────────────────────────────────────────────

function apiGetTickets(): void {
    global $user;
    $tickets = loadJson(APP_ROOT . '/data/tickets.json');

    // Role filter
    if ($user['role'] === 'user') {
        $tickets = array_values(array_filter($tickets, fn($t) => $t['created_by'] === $user['id']));
    }

    // Query filters (operator / admin)
    $status   = $_GET['status']      ?? '';
    $priority = $_GET['priority']    ?? '';
    $category = $_GET['category']    ?? '';
    $search   = $_GET['search']      ?? '';
    $assigned = $_GET['assigned_to'] ?? '';

    if ($status)   $tickets = array_values(array_filter($tickets, fn($t) => $t['status']   === $status));
    if ($priority) $tickets = array_values(array_filter($tickets, fn($t) => $t['priority'] === $priority));
    if ($category) $tickets = array_values(array_filter($tickets, fn($t) => $t['category'] === $category));
    if ($assigned) $tickets = array_values(array_filter($tickets, fn($t) => ($t['assigned_to'] ?? '') === $assigned));
    if ($search) {
        $s = strtolower($search);
        $tickets = array_values(array_filter($tickets, fn($t) =>
            str_contains(strtolower($t['title']), $s) ||
            str_contains(strtolower($t['description']), $s)
        ));
    }

    $total   = count($tickets);
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(50, (int)($_GET['per_page'] ?? 20)));
    $tickets = array_slice($tickets, ($page - 1) * $perPage, $perPage);

    // Enrich
    $umap = userMap();
    foreach ($tickets as &$t) {
        $t['created_by_name']  = $umap[$t['created_by']]['name'] ?? 'N/A';
        $t['assigned_to_name'] = $t['assigned_to'] ? ($umap[$t['assigned_to']]['name'] ?? 'N/A') : null;
    }

    jsonResponse([
        'tickets'     => $tickets,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function apiGetTicket(): void {
    global $user;
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'ID mancante'], 400);

    $ticket = findTicket($id);
    if (!$ticket) jsonResponse(['error' => 'Ticket non trovato'], 404);
    if ($user['role'] === 'user' && $ticket['created_by'] !== $user['id']) {
        jsonResponse(['error' => 'Permesso negato'], 403);
    }

    $umap = userMap();
    $ticket['created_by_name']  = $umap[$ticket['created_by']]['name'] ?? 'N/A';
    $ticket['assigned_to_name'] = $ticket['assigned_to'] ? ($umap[$ticket['assigned_to']]['name'] ?? 'N/A') : null;

    // Enrich history user names
    foreach ($ticket['history'] as &$h) {
        $h['by_name'] = $umap[$h['by']]['name'] ?? 'N/A';
    }

    jsonResponse(['ticket' => $ticket]);
}

function apiCreateTicket(): void {
    global $user;
    $d = jsonBody();
    $errors = validateRequired($d, ['title', 'description', 'category', 'priority']);
    if ($errors) jsonResponse(['error' => implode(', ', $errors)], 400);

    $ticket = [
        'id'          => generateId('t'),
        'title'       => sanitize($d['title']),
        'description' => sanitize($d['description']),
        'category'    => sanitize($d['category']),
        'priority'    => sanitize($d['priority']),
        'status'      => 'nuovo',
        'created_by'  => $user['id'],
        'assigned_to' => null,
        'created_at'  => nowIso(),
        'updated_at'  => nowIso(),
        'history'     => [
            ['action' => 'created', 'by' => $user['id'], 'at' => nowIso(), 'note' => 'Ticket creato', 'by_name' => $user['name']],
        ],
        'uploads' => [],
    ];

    $tickets   = loadJson(APP_ROOT . '/data/tickets.json');
    $tickets[] = $ticket;
    saveJson(APP_ROOT . '/data/tickets.json', $tickets);
    appendLog('ticket_created', $user['id'], "Ticket creato: {$ticket['id']} — {$ticket['title']}");

    // Email notifications (non-blocking)
    notifyNewTicket($ticket, $user);

    jsonResponse(['success' => true, 'ticket' => $ticket], 201);
}

function apiUpdateTicket(): void {
    global $user;
    $d  = jsonBody();
    $id = $d['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'ID mancante'], 400);

    $tickets    = loadJson(APP_ROOT . '/data/tickets.json');
    $found      = false;
    $statusChange  = null;
    $updatedTicket = null;

    foreach ($tickets as &$t) {
        if ($t['id'] !== $id) continue;
        $found = true;

        if (isset($d['status']) && $d['status'] !== $t['status']) {
            $old = $t['status'];
            $t['status'] = sanitize($d['status']);
            $t['history'][] = ['action' => 'status_changed', 'by' => $user['id'], 'by_name' => $user['name'],
                'at' => nowIso(), 'note' => "Stato: {$old} → {$t['status']}"];
            $statusChange = ['old' => $old, 'new' => $t['status']];
        }
        if (isset($d['priority']) && $d['priority'] !== $t['priority']) {
            $old = $t['priority'];
            $t['priority'] = sanitize($d['priority']);
            $t['history'][] = ['action' => 'priority_changed', 'by' => $user['id'], 'by_name' => $user['name'],
                'at' => nowIso(), 'note' => "Priorità: {$old} → {$t['priority']}"];
        }
        if (array_key_exists('assigned_to', $d)) {
            $t['assigned_to'] = $d['assigned_to'] ?: null;
            if ($t['assigned_to']) {
                $umap       = userMap();
                $assignee   = $umap[$t['assigned_to']] ?? null;
                $assignNote = $assignee
                    ? $assignee['name'] . ' (' . $assignee['role'] . ')'
                    : $t['assigned_to'];
            } else {
                $assignNote = 'nessuno';
            }
            $t['history'][] = ['action' => 'assigned', 'by' => $user['id'], 'by_name' => $user['name'],
                'at' => nowIso(), 'note' => 'Assegnato a: ' . $assignNote];
        }
        $t['updated_at'] = nowIso();
        $updatedTicket   = $t;
        break;
    }

    if (!$found) jsonResponse(['error' => 'Ticket non trovato'], 404);
    saveJson(APP_ROOT . '/data/tickets.json', $tickets);
    appendLog('ticket_updated', $user['id'], "Ticket aggiornato: {$id}");

    // Reset stale alert when ticket is assigned or resolved/closed
    $shouldClearAlert = false;
    if (array_key_exists('assigned_to', $d) && !empty($d['assigned_to'])) $shouldClearAlert = true;
    if ($statusChange && in_array($statusChange['new'], ['risolto', 'chiuso'], true)) $shouldClearAlert = true;
    if ($shouldClearAlert) clearStaleAlert($id);

    if ($statusChange && $updatedTicket) {
        notifyStatusChange($updatedTicket, $statusChange['old'], $statusChange['new'], $user);
    }

    jsonResponse(['success' => true]);
}

function apiDeleteTicket(): void {
    global $user;
    $d  = jsonBody();
    $id = $d['id'] ?? $_GET['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'ID mancante'], 400);

    $tickets = loadJson(APP_ROOT . '/data/tickets.json');
    $tickets = array_values(array_filter($tickets, fn($t) => $t['id'] !== $id));
    saveJson(APP_ROOT . '/data/tickets.json', $tickets);
    appendLog('ticket_deleted', $user['id'], "Ticket eliminato: {$id}");
    jsonResponse(['success' => true]);
}

// ── COMMENTS ─────────────────────────────────────────────────────────────────

function apiGetComments(): void {
    global $user;
    $tid = $_GET['ticket_id'] ?? '';
    if (!$tid) jsonResponse(['error' => 'ticket_id mancante'], 400);

    $ticket = findTicket($tid);
    if (!$ticket) jsonResponse(['error' => 'Ticket non trovato'], 404);
    if ($user['role'] === 'user' && $ticket['created_by'] !== $user['id']) {
        jsonResponse(['error' => 'Permesso negato'], 403);
    }

    $umap     = userMap();
    $comments = loadJson(APP_ROOT . '/data/comments.json');
    $comments = array_values(array_filter($comments, fn($c) => $c['ticket_id'] === $tid));
    foreach ($comments as &$c) {
        $c['user_name'] = $umap[$c['user_id']]['name'] ?? 'N/A';
        $c['user_role'] = $umap[$c['user_id']]['role'] ?? '';
    }
    jsonResponse(['comments' => $comments]);
}

function apiAddComment(): void {
    global $user;
    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart');
    $d  = $isMultipart ? $_POST : jsonBody();
    $tid = $d['ticket_id'] ?? '';
    $txt = $d['text']      ?? '';
    if (!$tid || !$txt) jsonResponse(['error' => 'ticket_id e testo sono obbligatori'], 400);

    $tickets = loadJson(APP_ROOT . '/data/tickets.json');
    $ticket  = null;
    foreach ($tickets as &$t) {
        if ($t['id'] === $tid) { $ticket = &$t; break; }
    }
    if (!$ticket) jsonResponse(['error' => 'Ticket non trovato'], 404);
    if ($user['role'] === 'user' && $ticket['created_by'] !== $user['id']) {
        jsonResponse(['error' => 'Permesso negato'], 403);
    }
    if ($user['role'] === 'user' && $ticket['status'] === 'chiuso') {
        jsonResponse(['error' => 'Non puoi commentare un ticket chiuso'], 400);
    }

    $uploads = [];
    if (!empty($_FILES['uploads'])) {
        $uploads = handleMultipleUploads($_FILES['uploads'], $tid);
    }

    $comment = [
        'id'         => generateId('c'),
        'ticket_id'  => $tid,
        'user_id'    => $user['id'],
        'text'       => sanitize($txt),
        'created_at' => nowIso(),
        'uploads'    => $uploads,
    ];

    $comments   = loadJson(APP_ROOT . '/data/comments.json');
    $comments[] = $comment;
    saveJson(APP_ROOT . '/data/comments.json', $comments);

    $ticket['updated_at'] = nowIso();
    $ticket['history'][]  = ['action' => 'comment_added', 'by' => $user['id'], 'by_name' => $user['name'],
        'at' => nowIso(), 'note' => 'Commento aggiunto'];
    saveJson(APP_ROOT . '/data/tickets.json', $tickets);

    appendLog('comment_added', $user['id'], "Commento su ticket: {$tid}");

    // Email notifications
    notifyNewComment($ticket, $comment, $user);

    $comment['user_name'] = $user['name'];
    $comment['user_role'] = $user['role'];
    jsonResponse(['success' => true, 'comment' => $comment], 201);
}

// ── USERS ─────────────────────────────────────────────────────────────────────

function apiGetUsers(): void {
    $users = loadJson(APP_ROOT . '/data/users.json');
    $safe  = array_map(fn($u) => array_diff_key($u, ['password' => '']), $users);
    jsonResponse(['users' => array_values($safe)]);
}

function apiCreateUser(): void {
    global $user;
    $d = jsonBody();
    $errors = validateRequired($d, ['username', 'password', 'email', 'role', 'name']);
    if ($errors) jsonResponse(['error' => implode(', ', $errors)], 400);
    if (!validateEmail($d['email'])) jsonResponse(['error' => 'Email non valida'], 400);
    if (!in_array($d['role'], ['user', 'operator', 'admin'], true)) jsonResponse(['error' => 'Ruolo non valido'], 400);

    $users = loadJson(APP_ROOT . '/data/users.json');
    foreach ($users as $u) {
        if ($u['username'] === $d['username']) jsonResponse(['error' => 'Username già in uso'], 400);
        if ($u['email']    === $d['email'])    jsonResponse(['error' => 'Email già in uso'],    400);
    }

    $new = [
        'id'         => generateId('u'),
        'username'   => sanitize($d['username']),
        'password'   => $d['password'],
        'email'      => sanitize($d['email']),
        'role'       => $d['role'],
        'name'       => sanitize($d['name']),
        'active'     => true,
        'created_at' => nowIso(),
    ];
    $users[] = $new;
    saveJson(APP_ROOT . '/data/users.json', $users);
    appendLog('user_created', $user['id'], "Utente creato: {$new['username']}");

    $safe = $new; unset($safe['password']);
    jsonResponse(['success' => true, 'user' => $safe], 201);
}

function apiUpdateUser(): void {
    global $user;
    $d  = jsonBody();
    $id = $d['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'ID mancante'], 400);

    $users = loadJson(APP_ROOT . '/data/users.json');
    $found = false;
    foreach ($users as &$u) {
        if ($u['id'] !== $id) continue;
        $found = true;
        if (isset($d['name']))     $u['name']   = sanitize($d['name']);
        if (isset($d['email']))    { if (!validateEmail($d['email'])) jsonResponse(['error' => 'Email non valida'], 400); $u['email'] = sanitize($d['email']); }
        if (isset($d['role']))     $u['role']   = $d['role'];
        if (isset($d['active']))   $u['active'] = (bool)$d['active'];
        if (!empty($d['password'])) $u['password'] = $d['password'];
        break;
    }
    if (!$found) jsonResponse(['error' => 'Utente non trovato'], 404);
    saveJson(APP_ROOT . '/data/users.json', $users);
    appendLog('user_updated', $user['id'], "Utente aggiornato: {$id}");
    jsonResponse(['success' => true]);
}

function apiDeleteUser(): void {
    global $user;
    $d  = jsonBody();
    $id = $d['id'] ?? $_GET['id'] ?? '';
    if (!$id) jsonResponse(['error' => 'ID mancante'], 400);
    if ($id === $user['id']) jsonResponse(['error' => 'Non puoi eliminare te stesso'], 400);

    $users = array_values(array_filter(loadJson(APP_ROOT . '/data/users.json'), fn($u) => $u['id'] !== $id));
    saveJson(APP_ROOT . '/data/users.json', $users);
    appendLog('user_deleted', $user['id'], "Utente eliminato: {$id}");
    jsonResponse(['success' => true]);
}

// ── UPLOAD ────────────────────────────────────────────────────────────────────

function apiUpload(): void {
    global $user;
    $tid = $_POST['ticket_id'] ?? '';
    if (!$tid) jsonResponse(['error' => 'ticket_id mancante'], 400);

    $ticket = findTicket($tid);
    if (!$ticket) jsonResponse(['error' => 'Ticket non trovato'], 404);
    if ($user['role'] === 'user' && $ticket['created_by'] !== $user['id']) jsonResponse(['error' => 'Permesso negato'], 403);
    if (empty($_FILES['file'])) jsonResponse(['error' => 'Nessun file'], 400);

    $path = handleUpload($_FILES['file'], $tid);
    if (!$path) jsonResponse(['error' => 'Errore upload (tipo/dimensione non validi)'], 422);

    $tickets = loadJson(APP_ROOT . '/data/tickets.json');
    foreach ($tickets as &$t) {
        if ($t['id'] === $tid) { $t['uploads'][] = $path; $t['updated_at'] = nowIso(); break; }
    }
    saveJson(APP_ROOT . '/data/tickets.json', $tickets);
    jsonResponse(['success' => true, 'path' => $path, 'url' => APP_URL . '/uploads/' . $path]);
}

// ── OPERATORS ─────────────────────────────────────────────────────────────────

function apiGetOperators(): void {
    $users = loadJson(APP_ROOT . '/data/users.json');
    $ops   = array_values(array_filter($users, fn($u) => in_array($u['role'], ['operator', 'admin'])));
    $ops   = array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'], 'role' => $u['role']], $ops);
    jsonResponse(['operators' => $ops]);
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────

function apiNotifications(): void {
    global $user;
    $notifications = [];

    if (in_array($user['role'], ['operator', 'admin'], true)) {
        $cutoff  = date('Y-m-d\TH:i:s', strtotime('-24 hours'));
        $tickets = loadJson(APP_ROOT . '/data/tickets.json');
        foreach ($tickets as $t) {
            if ($t['updated_at'] > $cutoff) {
                $notifications[] = [
                    'id'        => 'n_' . $t['id'],
                    'type'      => 'ticket_updated',
                    'message'   => "Ticket aggiornato: {$t['title']}",
                    'ticket_id' => $t['id'],
                    'at'        => $t['updated_at'],
                ];
            }
        }
        usort($notifications, fn($a, $b) => strcmp($b['at'], $a['at']));
    }

    jsonResponse(['notifications' => $notifications]);
}

// ── REPORT ────────────────────────────────────────────────────────────────────

function apiReport(): void {
    $tickets  = loadJson(APP_ROOT . '/data/tickets.json');
    $users    = loadJson(APP_ROOT . '/data/users.json');
    $comments = loadJson(APP_ROOT . '/data/comments.json');

    $byStatus = $byPriority = $byCategory = [];
    foreach ($tickets as $t) {
        $byStatus[$t['status']]     = ($byStatus[$t['status']]     ?? 0) + 1;
        $byPriority[$t['priority']] = ($byPriority[$t['priority']] ?? 0) + 1;
        $byCategory[$t['category']] = ($byCategory[$t['category']] ?? 0) + 1;
    }

    jsonResponse([
        'total_tickets'  => count($tickets),
        'total_users'    => count($users),
        'total_comments' => count($comments),
        'by_status'      => $byStatus,
        'by_priority'    => $byPriority,
        'by_category'    => $byCategory,
    ]);
}

// ── LOGS ──────────────────────────────────────────────────────────────────────

function apiLogs(): void {
    $logs    = array_reverse(loadJson(APP_ROOT . '/data/activity_log.json'));
    $total   = count($logs);
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;
    $logs    = array_slice($logs, ($page - 1) * $perPage, $perPage);

    $umap = userMap();
    foreach ($logs as &$l) $l['user_name'] = $umap[$l['user_id']]['name'] ?? 'N/A';

    jsonResponse(['logs' => $logs, 'total' => $total, 'page' => $page]);
}

// ── SETTINGS ─────────────────────────────────────────────────────────────────

function apiGetSettings(): void {
    $cfg = appSettings();
    $safe = $cfg;
    $safe['smtp_pass'] = $cfg['smtp_pass'] ? '••••••••' : ''; // mask password
    jsonResponse(['settings' => $safe]);
}

function apiSaveSettings(): void {
    $d    = jsonBody();
    $file = APP_ROOT . '/data/settings.json';
    $old  = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

    $allowed = ['brand_name','brand_logo','brand_color','support_email',
                'email_notifications','smtp_host','smtp_port','smtp_user',
                'smtp_from','smtp_from_name','smtp_encryption',
                'site_url','stale_alert_enabled','stale_alert_hours'];
    $new = $old;
    foreach ($allowed as $key) {
        if (array_key_exists($key, $d)) $new[$key] = $d[$key];
    }
    // Keep existing password if masked placeholder was returned
    if (isset($d['smtp_pass']) && $d['smtp_pass'] !== '••••••••') {
        $new['smtp_pass'] = $d['smtp_pass'];
    }
    saveJson($file, [$new]);
    // saveJson wraps in array_values; write raw object instead
    file_put_contents($file, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    jsonResponse(['success' => true]);
}

// ── CSV EXPORTS ───────────────────────────────────────────────────────────────

function csvRow(array $fields): string {
    return implode(',', array_map(function($v) {
        $v = str_replace('"', '""', (string)$v);
        return '"' . $v . '"';
    }, $fields)) . "\r\n";
}

function startCsv(string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
}

function apiExportTickets(): void {
    $tickets = loadJson(APP_ROOT . '/data/tickets.json');
    $umap    = array_column(loadJson(APP_ROOT . '/data/users.json'), null, 'id');
    startCsv('tickets_' . date('Ymd_His') . '.csv');
    echo csvRow(['ID','Titolo','Descrizione','Categoria','Priorità','Stato','Aperto da','Assegnato a','Creato il','Aggiornato il']);
    foreach ($tickets as $t) {
        echo csvRow([
            $t['id'], $t['title'], $t['description'], $t['category'],
            $t['priority'], $t['status'],
            $umap[$t['created_by']]['name']  ?? $t['created_by'],
            $t['assigned_to'] ? ($umap[$t['assigned_to']]['name'] ?? $t['assigned_to']) : '',
            $t['created_at'], $t['updated_at'],
        ]);
    }
    exit;
}

function apiExportLogs(): void {
    $logs = array_reverse(loadJson(APP_ROOT . '/data/activity_log.json'));
    $umap = array_column(loadJson(APP_ROOT . '/data/users.json'), null, 'id');
    startCsv('activity_log_' . date('Ymd_His') . '.csv');
    echo csvRow(['ID','Azione','Utente','Nota','Data/Ora']);
    foreach ($logs as $l) {
        echo csvRow([
            $l['id'], $l['action'],
            $umap[$l['user_id']]['name'] ?? $l['user_id'],
            $l['note'], $l['at'],
        ]);
    }
    exit;
}

function apiExportReport(): void {
    $tickets  = loadJson(APP_ROOT . '/data/tickets.json');
    $users    = loadJson(APP_ROOT . '/data/users.json');
    $comments = loadJson(APP_ROOT . '/data/comments.json');
    $byStatus = $byPriority = $byCategory = [];
    foreach ($tickets as $t) {
        $byStatus[$t['status']]     = ($byStatus[$t['status']]     ?? 0) + 1;
        $byPriority[$t['priority']] = ($byPriority[$t['priority']] ?? 0) + 1;
        $byCategory[$t['category']] = ($byCategory[$t['category']] ?? 0) + 1;
    }
    startCsv('report_' . date('Ymd_His') . '.csv');
    echo csvRow(['Metrica','Valore']);
    echo csvRow(['Ticket totali', count($tickets)]);
    echo csvRow(['Utenti totali', count($users)]);
    echo csvRow(['Commenti totali', count($comments)]);
    echo csvRow(['','']);
    echo csvRow(['--- Per stato ---','']);
    foreach ($byStatus   as $k => $v) echo csvRow([$k, $v]);
    echo csvRow(['','']);
    echo csvRow(['--- Per priorità ---','']);
    foreach ($byPriority as $k => $v) echo csvRow([$k, $v]);
    echo csvRow(['','']);
    echo csvRow(['--- Per categoria ---','']);
    foreach ($byCategory as $k => $v) echo csvRow([$k, $v]);
    exit;
}

// ── HELPERS ───────────────────────────────────────────────────────────────────

function jsonBody(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? $_POST) : $_POST;
}

function findTicket(string $id): ?array {
    foreach (loadJson(APP_ROOT . '/data/tickets.json') as $t) {
        if ($t['id'] === $id) return $t;
    }
    return null;
}

function userMap(): array {
    return array_column(loadJson(APP_ROOT . '/data/users.json'), null, 'id');
}

// ── STALE TICKET CHECK ────────────────────────────────────────────────────────

function apiStaleCheck(): void {
    $result = runStaleCheck();
    jsonResponse(['success' => true, 'result' => $result]);
}
