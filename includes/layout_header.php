<?php
$pageTitle = $pageTitle ?? 'Ma Cave';
$activeNav = $activeNav ?? '';
$colorTheme = app_color_theme();
$themeBg = COLOR_THEMES[$colorTheme]['bg'];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($colorTheme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Ma Cave</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: time() ?>">
<link rel="icon" href="/assets/logo.png?v=<?= @filemtime(__DIR__ . '/../assets/logo.png') ?: time() ?>">

<!-- Installation sur l'écran d'accueil (PWA) -->
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<meta name="theme-color" content="<?= e($themeBg) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Ma Cave">

<?php if (is_logged_in()): ?><meta name="csrf-token" content="<?= e(csrf_token()) ?>"><?php endif; ?>
</head>
<body>
<?php if (is_logged_in()): ?>
<header class="site-header">
    <div class="site-header-inner">
        <a href="/pages/index.php" class="brand"><img src="/assets/logo.png?v=<?= @filemtime(__DIR__ . '/../assets/logo.png') ?: time() ?>" alt="Ma Cave" class="brand-logo"></a>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Ouvrir le menu" aria-expanded="false"><?= icon('menu', 22) ?></button>
        <nav class="main-nav">
            <a href="/pages/index.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><?= icon('dashboard') ?> Tableau de bord</a>
            <a href="/pages/wines_list.php" class="<?= $activeNav === 'wines' ? 'active' : '' ?>"><?= icon('bottle') ?> Mes vins</a>
            <a href="/pages/rack.php" class="<?= $activeNav === 'rack' ? 'active' : '' ?>"><?= icon('rack') ?> Casier</a>
            <a href="/pages/scan_info.php" class="<?= $activeNav === 'scan' ? 'active' : '' ?>"><?= icon('barcode') ?> Scanner</a>
            <a href="/pages/consumption.php" class="<?= $activeNav === 'consumption' ? 'active' : '' ?>"><?= icon('clock') ?> Historique</a>
            <a href="/pages/stats.php" class="<?= $activeNav === 'stats' ? 'active' : '' ?>"><?= icon('chart') ?> Statistiques</a>
            <a href="/pages/settings.php" class="<?= $activeNav === 'settings' ? 'active' : '' ?>"><?= icon('settings') ?> Paramètres</a>
        </nav>
        <div class="header-search">
            <?= icon('search', 16) ?>
            <input type="text" id="global-search-input" placeholder="Rechercher un vin..." autocomplete="off">
            <div id="global-search-results" class="global-search-results"></div>
        </div>
        <div class="header-actions">
            <a href="/pages/wine_form.php" class="btn btn-accent"><?= icon('plus') ?> Ajouter un vin</a>
            <a href="/pages/login.php?logout=1" class="btn btn-ghost"><?= icon('logout') ?> Déconnexion</a>
        </div>
    </div>
</header>
<?php endif; ?>
<main class="page-container">
