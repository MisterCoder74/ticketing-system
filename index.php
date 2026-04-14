<?php
// index.php — Login + Ticket list

require_once __DIR__ . '/inc/auth.php';

$version = date('YmdHis');

// Handle login POST
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    $u = login(trim($_POST['username'] ?? ''), trim($_POST['password'] ?? ''));
    if ($u) {
        $_SESSION['user'] = $u;
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
    $loginError = 'Credenziali non valide. Riprova.';
}

// Handle logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$loggedIn = isLoggedIn();
$user     = currentUser();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ticketing System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/bootstrap.min.css?v=<?php echo $version; ?>">
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css?v=<?php echo $version; ?>">
</head>
<body class="bg-light">

<?php if (!$loggedIn): ?>
<!-- ========== LOGIN ========== -->
<div class="d-flex align-items-center justify-content-center min-vh-100">
  <div class="card shadow-sm" style="width:100%;max-width:400px">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <span class="fs-2">🎫</span>
        <h4 class="mt-2 mb-0 fw-bold">Ticketing System</h4>
        <p class="text-muted small">Accedi al tuo account</p>
      </div>
      <?php if ($loginError): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($loginError); ?></div>
      <?php endif; ?>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="do_login" value="1">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Accedi</button>
      </form>
      <p class="text-center mt-3 mb-0 small text-muted">
        Non hai un account? Contatta l'amministratore.
      </p>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ========== MAIN APP ========== -->
<nav class="navbar navbar-dark bg-primary px-3">
  <span class="navbar-brand fw-bold">🎫 Ticketing System</span>
  <div class="d-flex align-items-center gap-3">
    <?php if (isOperator()): ?>
      <span id="notif-badge" class="badge bg-warning text-dark d-none" role="button" data-bs-toggle="offcanvas" data-bs-target="#notifPanel">0 nuovi</span>
    <?php endif; ?>
    <span class="text-white-50 small">
      <?php echo htmlspecialchars($user['name']); ?>
      <span class="badge bg-secondary ms-1"><?php echo $user['role']; ?></span>
    </span>
    <a href="<?php echo APP_URL; ?>/index.php?logout=1" class="btn btn-sm btn-outline-light">Esci</a>
  </div>
</nav>

<?php if (isAdmin()): ?>
<!-- Admin tabs -->
<div class="container-fluid mt-3">
  <ul class="nav nav-tabs mb-3" id="adminTabs">
    <li class="nav-item"><a class="nav-link active" data-tab="tickets" href="#">🎫 Ticket</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="users"   href="#">👤 Utenti</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="report"  href="#">📊 Report</a></li>
    <li class="nav-item"><a class="nav-link" data-tab="logs"    href="#">📋 Log Attività</a></li>
  </ul>
  <div id="tab-tickets"><!-- ticket list injected here --></div>
  <div id="tab-users"   class="d-none"><!-- users injected here --></div>
  <div id="tab-report"  class="d-none"><!-- report injected here --></div>
  <div id="tab-logs"    class="d-none"><!-- logs injected here --></div>
</div>
<?php else: ?>
<div class="container-fluid mt-3" id="tab-tickets"><!-- ticket list injected here --></div>
<?php endif; ?>

<!-- Notifications offcanvas (operator/admin) -->
<?php if (isOperator()): ?>
<div class="offcanvas offcanvas-end" id="notifPanel" tabindex="-1">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">🔔 Notifiche (ultime 24h)</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="notif-list"><p class="text-muted">Caricamento…</p></div>
</div>
<?php endif; ?>

<?php endif; // end logged in ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const APP_URL  = '<?php echo APP_URL; ?>';
  const ROLE     = '<?php echo $loggedIn ? $user['role'] : ''; ?>';
  const LOGGED_IN = <?php echo $loggedIn ? 'true' : 'false'; ?>;
  const PAGE      = 'index';
</script>
<script src="<?php echo APP_URL; ?>/assets/js/app.js?v=<?php echo $version; ?>"></script>
</body>
</html>
