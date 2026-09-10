<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

start_secure_session();

if (isset($_GET['logout'])) {
    logout();
    header('Location: /pages/login.php');
    exit;
}

if (is_logged_in()) {
    header('Location: /pages/index.php');
    exit;
}

$db = get_db();
$ip = client_ip();

$error = null;
$lockedUntil = login_locked_until($db, $ip);
if ($lockedUntil) {
    $error = 'Trop de tentatives échouées. Réessaie après ' . date('H:i', strtotime($lockedUntil)) . '.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!hash_equals(ADMIN_USERNAME, $username)) {
        // Identifiant différent de l'admin : signe probable de scan/bot, blocage immédiat 24h.
        blacklist_ip($db, $ip, LOGIN_BLACKLIST_SECONDS);
        $error = 'Identifiants incorrects.';
    } elseif (attempt_login($db, $username, $password)) {
        clear_login_attempts($db, $ip);
        header('Location: /pages/index.php');
        exit;
    } else {
        register_failed_login($db, $ip);
        $error = 'Identifiants incorrects.';
    }
}

$pageTitle = 'Connexion';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="login-page">
    <div class="card login-card">
        <div style="text-align:center; margin-bottom:1rem;">
            <img src="/assets/logo.png?v=<?= @filemtime(__DIR__ . '/../assets/logo.png') ?: time() ?>" alt="Ma Cave" style="width:120px; height:auto;">
            <p style="color:var(--text-muted); margin:0.5rem 0 0;">Connexion à l'espace privé</p>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="username">Identifiant</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-accent" style="width:100%;">Se connecter</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
