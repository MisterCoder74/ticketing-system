<?php
// inc/mailer.php — Email notifications (requires auth.php for APP_ROOT/APP_URL/loadJson)

// ── Core sender ───────────────────────────────────────────────────────────────

function sendNotification(string $to, string $subject, string $htmlBody): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $cfg = appSettings();
    if (empty($cfg['email_notifications'])) return false;

    $fromEmail = $cfg['smtp_from'] ?: ($cfg['support_email'] ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName  = $cfg['smtp_from_name'] ?: $cfg['brand_name'];

    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user']) && !empty($cfg['smtp_pass'])) {
        return smtpSend($to, $subject, $htmlBody, $fromEmail, $fromName, $cfg);
    }

    // Fallback: PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION;
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
}

function smtpSend(string $to, string $subject, string $html, string $from, string $fromName, array $cfg): bool {
    $host = $cfg['smtp_host'];
    $port = (int)($cfg['smtp_port'] ?? 587);
    $enc  = strtolower($cfg['smtp_encryption'] ?? 'tls');

    $socketHost = ($enc === 'ssl') ? "ssl://{$host}" : $host;
    $errno = 0; $errstr = '';
    $sock = @fsockopen($socketHost, $port, $errno, $errstr, 10);
    if (!$sock) return false;
    stream_set_timeout($sock, 10);

    $read = static fn() => (string)fgets($sock, 515);
    $cmd  = static function(string $c) use ($sock, $read): string {
        fwrite($sock, $c . "\r\n"); return $read();
    };

    $read(); // greeting
    $r = $cmd("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    while (isset($r[3]) && $r[3] === '-') $r = $read(); // consume multi-line EHLO

    if ($enc === 'tls') {
        $cmd("STARTTLS");
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock); return false;
        }
        $r = $cmd("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        while (isset($r[3]) && $r[3] === '-') $r = $read();
    }

    $cmd("AUTH LOGIN");
    $cmd(base64_encode($cfg['smtp_user']));
    $r = $cmd(base64_encode($cfg['smtp_pass']));
    if (!str_starts_with(trim($r), '235')) { fclose($sock); return false; }

    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$to}>");
    $cmd("DATA");

    $msg  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($html), 76, "\r\n");

    fwrite($sock, $msg . "\r\n.\r\n");
    $resp = $read();
    $cmd("QUIT");
    fclose($sock);
    return str_starts_with(trim($resp), '250');
}

// ── HTML template ─────────────────────────────────────────────────────────────

function emailTemplate(string $title, string $contentHtml): string {
    $cfg   = appSettings();
    $brand = htmlspecialchars($cfg['brand_name']);
    $color = htmlspecialchars($cfg['brand_color'] ?: '#0d6efd');
    return <<<HTML
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
  <tr><td style="background:{$color};padding:20px 28px"><span style="color:#fff;font-size:22px;font-weight:bold">🎫 {$brand}</span></td></tr>
  <tr><td style="padding:28px"><h2 style="margin:0 0 16px;color:#222;font-size:18px">{$title}</h2><div style="color:#444;line-height:1.6">{$contentHtml}</div></td></tr>
  <tr><td style="background:#f0f0f0;padding:14px 28px;font-size:12px;color:#999;text-align:center">Messaggio automatico generato da {$brand} — non rispondere.</td></tr>
</table></td></tr></table></body></html>
HTML;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function getStaff(): array {
    return array_filter(loadJson(APP_ROOT . '/data/users.json'), static fn($u) =>
        in_array($u['role'], ['admin', 'operator'], true)
        && !empty($u['email'])
        && filter_var($u['email'], FILTER_VALIDATE_EMAIL)
        && ($u['active'] ?? true)
    );
}

function getTicketOpenerUser(array $ticket): ?array {
    foreach (loadJson(APP_ROOT . '/data/users.json') as $u) {
        if ($u['id'] === $ticket['created_by']
            && !empty($u['email'])
            && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) return $u;
    }
    return null;
}

function ticketUrl(string $id): string {
    $base = APP_URL;
    // In CLI/cron mode APP_URL may be a filesystem path — fall back to configured site_url
    if (!str_starts_with($base, 'http')) {
        $base = rtrim(appSettings()['site_url'] ?? '', '/');
    }
    return $base . '/pages/ticket_details.php?id=' . urlencode($id);
}

function btnLink(string $url, string $color, string $label): string {
    $c = htmlspecialchars($color);
    return "<p><a href='{$url}' style='background:{$c};color:#fff;padding:10px 22px;border-radius:4px;text-decoration:none;font-weight:bold;display:inline-block'>{$label} →</a></p>";
}

// ── Dispatchers ───────────────────────────────────────────────────────────────

function notifyNewTicket(array $ticket, array $creator): void {
    $cfg = appSettings();
    if (empty($cfg['email_notifications'])) return;
    $brand = htmlspecialchars($cfg['brand_name']);
    $color = $cfg['brand_color'] ?: '#0d6efd';
    $title = htmlspecialchars($ticket['title']);
    $by    = htmlspecialchars($creator['name']);

    $rows = implode('', array_map(
        fn($k, $v) => "<tr><td style='padding:7px 12px;color:#666;width:130px'>{$k}</td><td style='padding:7px 12px'>{$v}</td></tr>",
        ['ID', 'Titolo', 'Categoria', 'Priorità'],
        [htmlspecialchars($ticket['id']), "<strong>{$title}</strong>",
         htmlspecialchars($ticket['category']), htmlspecialchars($ticket['priority'])]
    ));
    $content = "<p>Un nuovo ticket è stato aperto da <strong>{$by}</strong>.</p>"
             . "<table style='width:100%;border-collapse:collapse;font-size:14px;margin:16px 0;background:#f9f9f9'>{$rows}</table>"
             . btnLink(ticketUrl($ticket['id']), $color, 'Visualizza Ticket');

    $html    = emailTemplate("Nuovo ticket: {$title}", $content);
    $subject = "[{$brand}] Nuovo ticket: {$title}";
    foreach (getStaff() as $u) sendNotification($u['email'], $subject, $html);
}

function notifyStatusChange(array $ticket, string $oldStatus, string $newStatus, array $changedBy): void {
    $cfg = appSettings();
    if (empty($cfg['email_notifications'])) return;
    $brand = htmlspecialchars($cfg['brand_name']);
    $color = $cfg['brand_color'] ?: '#0d6efd';
    $title = htmlspecialchars($ticket['title']);
    $by    = htmlspecialchars($changedBy['name']);

    $content = "<p>Lo stato del ticket <strong>{$title}</strong> è stato modificato da <strong>{$by}</strong>.</p>"
             . "<table style='width:100%;border-collapse:collapse;font-size:14px;margin:16px 0;background:#f9f9f9'>"
             . "<tr><td style='padding:7px 12px;color:#666;width:160px'>Stato precedente</td><td style='padding:7px 12px'>" . htmlspecialchars($oldStatus) . "</td></tr>"
             . "<tr><td style='padding:7px 12px;color:#666'>Nuovo stato</td><td style='padding:7px 12px'><strong>" . htmlspecialchars($newStatus) . "</strong></td></tr>"
             . "</table>"
             . btnLink(ticketUrl($ticket['id']), $color, 'Visualizza Ticket');

    $html    = emailTemplate("Stato aggiornato: {$title}", $content);
    $subject = "[{$brand}] Ticket aggiornato: {$title}";

    $notified = [];
    foreach (getStaff() as $u) {
        sendNotification($u['email'], $subject, $html);
        $notified[$u['id']] = true;
    }
    $opener = getTicketOpenerUser($ticket);
    if ($opener && empty($notified[$opener['id']])) {
        sendNotification($opener['email'], $subject, $html);
    }
}

function notifyNewComment(array $ticket, array $comment, array $commenter): void {
    $cfg = appSettings();
    if (empty($cfg['email_notifications'])) return;
    $brand  = htmlspecialchars($cfg['brand_name']);
    $color  = $cfg['brand_color'] ?: '#0d6efd';
    $title  = htmlspecialchars($ticket['title']);
    $by     = htmlspecialchars($commenter['name']);
    $text   = nl2br(htmlspecialchars($comment['text']));

    $content = "<p><strong>{$by}</strong> ha aggiunto un commento al ticket <strong>{$title}</strong>.</p>"
             . "<blockquote style='border-left:4px solid #ddd;margin:16px 0;padding:10px 16px;background:#f9f9f9;color:#555;border-radius:0 4px 4px 0'>{$text}</blockquote>"
             . btnLink(ticketUrl($ticket['id']), $color, 'Visualizza Ticket');

    $html    = emailTemplate("Nuovo commento: {$title}", $content);
    $subject = "[{$brand}] Commento su ticket: {$title}";

    $recipients = [];
    if ($commenter['role'] === 'user') {
        foreach (getStaff() as $u) $recipients[$u['id']] = $u;
    } else {
        $opener = getTicketOpenerUser($ticket);
        if ($opener) $recipients[$opener['id']] = $opener;
    }
    foreach ($recipients as $u) {
        if ($u['id'] !== $commenter['id']) sendNotification($u['email'], $subject, $html);
    }
}

// ── Stale ticket alert ────────────────────────────────────────────────────────

/**
 * Send an alert email to all admins for a ticket that has been
 * unassigned or open (stato: nuovo) for too long.
 */
function notifyStaleTicket(array $ticket): bool {
    $cfg = appSettings();
    if (empty($cfg['email_notifications'])) return false;

    $color  = $cfg['brand_color'] ?: '#0d6efd';
    $id     = htmlspecialchars($ticket['id']);
    $title  = htmlspecialchars($ticket['title'] ?? '');
    $status = htmlspecialchars($ticket['status'] ?? '');
    $prio   = htmlspecialchars($ticket['priority'] ?? '');
    $cat    = htmlspecialchars($ticket['category'] ?? '');
    $desc   = nl2br(htmlspecialchars(mb_substr($ticket['description'] ?? '', 0, 300)));

    $umap       = userMap();
    $createdBy  = htmlspecialchars($umap[$ticket['created_by'] ?? '']['name'] ?? ($ticket['created_by'] ?? '—'));
    $assignedTo = $ticket['assigned_to']
        ? htmlspecialchars($umap[$ticket['assigned_to']]['name'] ?? $ticket['assigned_to'])
        : '<em style="color:#dc3545">Non assegnato</em>';

    $createdAt = new DateTimeImmutable($ticket['created_at']);
    $hoursOld  = round((time() - $createdAt->getTimestamp()) / 3600, 1);

    $rows = implode('', [
        "<tr><td style='padding:8px 14px;color:#666;width:150px;border-bottom:1px solid #eee'>🎫 ID</td><td style='padding:8px 14px;border-bottom:1px solid #eee'><strong>{$id}</strong></td></tr>",
        "<tr><td style='padding:8px 14px;color:#666;border-bottom:1px solid #eee'>📝 Titolo</td><td style='padding:8px 14px;border-bottom:1px solid #eee'><strong>{$title}</strong></td></tr>",
        "<tr><td style='padding:8px 14px;color:#666;border-bottom:1px solid #eee'>📊 Stato</td><td style='padding:8px 14px;border-bottom:1px solid #eee'>{$status}</td></tr>",
        "<tr><td style='padding:8px 14px;color:#666;border-bottom:1px solid #eee'>🔥 Priorità</td><td style='padding:8px 14px;border-bottom:1px solid #eee'>{$prio}</td></tr>",
        "<tr><td style='padding:8px 14px;color:#666;border-bottom:1px solid #eee'>🗂️ Categoria</td><td style='padding:8px 14px;border-bottom:1px solid #eee'>{$cat}</td></tr>",
        "<tr><td style='padding:8px 14px;color:#666;border-bottom:1px solid #eee'>👤 Aperto da</td><td style='padding:8px 14px;border-bottom:1px solid #eee'>{$createdBy}</td></tr>",
        "<tr><td style='padding:8px 14px;color:#666;border-bottom:1px solid #eee'>🧑‍💼 Assegnato a</td><td style='padding:8px 14px;border-bottom:1px solid #eee'>{$assignedTo}</td></tr>",
        "<tr><td style='padding:8px 14px;color:#666'>⏱️ Aperto il</td><td style='padding:8px 14px'>"
            . $createdAt->format('d/m/Y H:i')
            . " <span style='background:#dc3545;color:#fff;border-radius:12px;padding:2px 9px;font-size:12px;font-weight:bold'>{$hoursOld}h fa</span></td></tr>",
    ]);

    $content = "<div style='background:#fff3cd;border-left:4px solid #ffc107;border-radius:0 6px 6px 0;padding:14px 18px;margin-bottom:20px'>"
             . "<strong style='color:#664d03'>⚠️ Attenzione</strong><br>"
             . "<span style='color:#664d03'>Il ticket <strong>n° {$id}</strong> non è stato gestito da <strong>{$hoursOld} ore</strong>.</span>"
             . "</div>"
             . "<table style='width:100%;border-collapse:collapse;font-size:14px;background:#f9f9f9;border-radius:6px;overflow:hidden'>{$rows}</table>"
             . "<div style='margin:20px 0'>"
             . "<p style='color:#555;font-size:13px;margin:0 0 6px'><strong>Descrizione:</strong></p>"
             . "<div style='background:#f0f0f0;border-radius:4px;padding:12px 16px;color:#555;font-size:13px;line-height:1.6'>{$desc}</div>"
             . "</div>"
             . btnLink(ticketUrl($ticket['id']), '#dc3545', '🔴 Gestisci Ticket Ora');

    $html    = emailTemplate('⚠️ Ticket in attesa di gestione', $content);
    $subject = "Ticketing System alert for n° {$id} still unassigned or open";

    $sent = false;
    foreach (loadJson(APP_ROOT . '/data/users.json') as $u) {
        if (($u['role'] ?? '') !== 'admin') continue;
        if (empty($u['email']) || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        if (!($u['active'] ?? true)) continue;
        if (sendNotification($u['email'], $subject, $html)) $sent = true;
    }
    return $sent;
}

/**
 * Core stale-ticket check logic — shared between the cron script and the admin API.
 * Returns ['checked', 'alerted', 'timestamp', 'errors'].
 */
function runStaleCheck(): array {
    $cfg         = appSettings();
    $threshHours = max(1, (int)($cfg['stale_alert_hours'] ?? 12));
    $staleFile   = APP_ROOT . '/data/stale_alerts.json';
    $tickets     = loadJson(APP_ROOT . '/data/tickets.json');
    $staleLog    = file_exists($staleFile) ? (json_decode(file_get_contents($staleFile), true) ?? []) : [];
    if (!is_array($staleLog)) $staleLog = [];

    $now     = new DateTimeImmutable();
    $checked = 0;
    $alerted = 0;
    $errors  = [];

    foreach ($tickets as $ticket) {
        $status = $ticket['status'] ?? '';

        // Skip resolved / closed
        if (in_array($status, ['risolto', 'chiuso'], true)) continue;

        // Condition: unassigned OR open (nuovo)
        $isUnassigned = empty($ticket['assigned_to']);
        $isOpen       = ($status === 'nuovo');
        if (!$isUnassigned && !$isOpen) continue;

        $checked++;
        $ticketId = $ticket['id'];

        // Already alerted for this ticket? Skip (will re-alert only after reset)
        if (isset($staleLog[$ticketId])) continue;

        // Check age against created_at
        try {
            $createdAt = new DateTimeImmutable($ticket['created_at']);
        } catch (\Throwable $e) {
            continue;
        }
        $hoursOld = ($now->getTimestamp() - $createdAt->getTimestamp()) / 3600;
        if ($hoursOld < $threshHours) continue;

        // Send alert
        if (notifyStaleTicket($ticket)) {
            $staleLog[$ticketId] = nowIso();
            $alerted++;
        } else {
            $errors[] = "Send failed for ticket {$ticketId} (check SMTP settings)";
        }
    }

    // Persist alert log
    file_put_contents($staleFile, json_encode($staleLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    appendLog('stale_check', 'system', "Verifica ticket scaduti: {$checked} analizzati, {$alerted} alert inviati.");

    return ['checked' => $checked, 'alerted' => $alerted, 'timestamp' => nowIso(), 'errors' => $errors];
}

/**
 * Remove a ticket from the stale-alerts log so it can trigger again if it
 * becomes stale after being assigned or resolved/closed.
 */
function clearStaleAlert(string $ticketId): void {
    $file = APP_ROOT . '/data/stale_alerts.json';
    if (!file_exists($file)) return;
    $log = json_decode(file_get_contents($file), true) ?? [];
    if (!is_array($log) || !isset($log[$ticketId])) return;
    unset($log[$ticketId]);
    file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

