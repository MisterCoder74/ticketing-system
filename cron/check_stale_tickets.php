<?php
/**
 * cron/check_stale_tickets.php
 *
 * Sends alert emails to admin(s) for tickets that have been unassigned or
 * open (stato: "nuovo") for longer than the configured threshold (default: 12h).
 * Each ticket triggers at most one alert; the alert resets when the ticket is
 * assigned, resolved, or closed.
 *
 * ── Server cron (recommended: every 30 min) ──────────────────────────────────
 *   * /30 * * * * php /full/path/to/cron/check_stale_tickets.php >> /tmp/stale.log 2>&1
 *
 * ── Manual HTTP trigger ──────────────────────────────────────────────────────
 *   From the admin panel → Impostazioni → Alert Ticket Scaduti → "Esegui ora"
 *   (calls the 'stale_check' API action — no direct HTTP access to this file needed)
 */

define('CRON_MODE', true);

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
require_once $root . '/inc/auth.php';    // APP_ROOT, APP_URL, loadJson, saveJson, appSettings, nowIso
require_once $root . '/inc/helpers.php'; // appendLog, nowIso
require_once $root . '/inc/mailer.php';  // notifyStaleTicket, runStaleCheck

$result = runStaleCheck();

if (php_sapi_name() === 'cli') {
    echo '[' . date('Y-m-d H:i:s') . '] Stale check: '
        . $result['checked'] . ' eligible, '
        . $result['alerted'] . ' alerted.' . PHP_EOL;
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $e) {
            echo '  ERROR: ' . $e . PHP_EOL;
        }
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
}
