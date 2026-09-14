<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'purge_log') {
        $db->exec('DELETE FROM login_attempt_log');
        header('Location: /pages/security_log.php?done=purge');
        exit;
    }
}

$success = $_GET['done'] ?? null;

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) $db->query('SELECT COUNT(*) FROM login_attempt_log')->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare('SELECT * FROM login_attempt_log ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$distinctIps = (int) $db->query('SELECT COUNT(DISTINCT ip_address) FROM login_attempt_log')->fetchColumn();
$blockedCount = (int) $db->query("SELECT COUNT(*) FROM login_attempt_log WHERE result = 'fail_username'")->fetchColumn();

$RESULT_BADGES = [
    'success' => ['badge-success', 'Connexion réussie'],
    'fail_password' => ['badge-other', 'Mot de passe incorrect'],
    'fail_username' => ['badge-alert', 'Identifiant inconnu — bloqué 24h'],
];

$pageTitle = 'Journal de sécurité';
$activeNav = 'settings';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <h1>Journal de sécurité</h1>
    <div class="header-actions">
        <a href="/pages/maintenance.php" class="btn btn-ghost"><?= icon('settings', 16) ?> Maintenance</a>
    </div>
</div>
<p style="color:var(--text-muted); margin-top:-0.5rem;">
    Historique complet des tentatives de connexion : une ligne par essai, avec date, IP, pays (fourni par Cloudflare) et résultat.
    Différent du tableau « IP bloquées » de la Maintenance, qui ne montre que l'état de blocage actuel.
</p>

<?php if ($success === 'purge'): ?><div class="alert alert-success">Journal vidé.</div><?php endif; ?>

<div class="section grid grid-3">
    <div class="card stat-tile">
        <div class="value"><?= $total ?></div>
        <div class="label">Tentatives enregistrées</div>
    </div>
    <div class="card stat-tile">
        <div class="value"><?= $distinctIps ?></div>
        <div class="label">IP distinctes</div>
    </div>
    <div class="card stat-tile">
        <div class="value"><?= $blockedCount ?></div>
        <div class="label">Bloquées d'emblée (identifiant inconnu)</div>
    </div>
</div>

<div class="card section">
    <?php if (!$rows): ?>
        <div class="empty-state">Aucune tentative de connexion enregistrée pour l'instant.</div>
    <?php else: ?>
    <table class="responsive-table">
        <thead>
            <tr>
                <th scope="col">Date &amp; heure</th>
                <th scope="col">Adresse IP</th>
                <th scope="col">Pays</th>
                <th scope="col">Identifiant tenté</th>
                <th scope="col">Résultat</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): [$badgeClass, $badgeLabel] = $RESULT_BADGES[$r['result']] ?? ['badge-other', $r['result']]; ?>
            <tr>
                <td data-label="Date &amp; heure"><?= e(date('d/m/Y H:i:s', strtotime($r['created_at']))) ?></td>
                <td data-label="Adresse IP"><?= e($r['ip_address']) ?></td>
                <td data-label="Pays"><?= country_flag($r['country_code']) ?> <?= e($r['country_code'] ?? '—') ?></td>
                <td data-label="Identifiant tenté"><?= e($r['username_tried'] ?? '—') ?></td>
                <td data-label="Résultat"><span class="badge <?= $badgeClass ?>"><?= e($badgeLabel) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <nav class="form-row" style="margin-top:1rem; justify-content:space-between; align-items:center;" aria-label="Pagination du journal">
        <?php if ($page > 1): ?>
            <a href="/pages/security_log.php?page=<?= $page - 1 ?>" class="btn btn-sm">&larr; Précédent</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <span style="color:var(--text-muted); font-size:0.85rem;">Page <?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="/pages/security_log.php?page=<?= $page + 1 ?>" class="btn btn-sm">Suivant &rarr;</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('Vider tout le journal de sécurité ? Cette action est irréversible.');" style="margin-top:1.2rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge_log">
        <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash', 15) ?> Vider le journal</button>
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
