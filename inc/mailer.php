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
    return APP_URL . '/pages/ticket_details.php?id=' . urlencode($id);
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
