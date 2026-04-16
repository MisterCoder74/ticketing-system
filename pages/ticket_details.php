<?php
// pages/ticket_details.php — Ticket detail view

require_once dirname(__DIR__) . '/inc/auth.php';
requireLogin();

$version  = date('YmdHis');
$user     = currentUser();
$ticketId = htmlspecialchars($_GET['id'] ?? '');
if (!$ticketId) { header('Location: ' . APP_URL . '/index.php'); exit; }
$s         = appSettings();
$brandName  = htmlspecialchars($s['brand_name']);
$brandColor = htmlspecialchars($s['brand_color'] ?: '#0d6efd');
$brandLogo  = htmlspecialchars($s['brand_logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dettaglio Ticket — <?php echo $brandName; ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/bootstrap.min.css?v=<?php echo $version; ?>">
  <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css?v=<?php echo $version; ?>">
  <style>
    .bg-primary, nav.navbar { background-color: <?php echo $brandColor; ?> !important; }
    .btn-primary { background-color: <?php echo $brandColor; ?> !important; border-color: <?php echo $brandColor; ?> !important; }
    .btn-outline-primary { color: <?php echo $brandColor; ?> !important; border-color: <?php echo $brandColor; ?> !important; }
    .text-primary { color: <?php echo $brandColor; ?> !important; }
  </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand fw-bold" href="<?php echo APP_URL; ?>/index.php">
    <?php if ($brandLogo): ?><img src="<?php echo $brandLogo; ?>" height="26" class="me-2 align-text-bottom" alt=""><?php else: ?>🎫<?php endif; ?>
    <?php echo $brandName; ?>
  </a>
  <div class="d-flex align-items-center gap-3">
    <span class="text-white-50 small">
      <?php echo htmlspecialchars($user['name']); ?>
      <span class="badge bg-secondary ms-1"><?php echo $user['role']; ?></span>
    </span>
    <a href="<?php echo APP_URL; ?>/index.php?logout=1" class="btn btn-sm btn-outline-light">Esci</a>
  </div>
</nav>

<div class="container mt-4" id="ticket-container">
  <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const APP_URL   = '<?php echo APP_URL; ?>';
  const ROLE      = '<?php echo $user['role']; ?>';
  const LOGGED_IN = true;
  const PAGE      = 'detail';
  const TICKET_ID = '<?php echo $ticketId; ?>';
</script>
<script src="<?php echo APP_URL; ?>/assets/js/app.js?v=<?php echo $version; ?>"></script>
</body>
</html>
