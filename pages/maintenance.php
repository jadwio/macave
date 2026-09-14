<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$success = $_GET['done'] ?? null;

// Photos orphelines : présentes dans uploads/labels/ mais référencées par aucun vin.
function find_orphan_photos(PDO $db): array
{
    $used = $db->query('SELECT label_photo_path FROM wines WHERE label_photo_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $usedNames = array_map('basename', $used);
    $dir = __DIR__ . '/../uploads/labels';
    $orphans = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f[0] === '.' || !is_file("$dir/$f")) {
                continue;
            }
            if (!in_array($f, $usedNames, true)) {
                $orphans[] = ['name' => $f, 'size' => filesize("$dir/$f")];
            }
        }
    }
    return $orphans;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_ai_logs') {
        $db->exec('DELETE FROM ai_enrichment_log');
        header('Location: /pages/maintenance.php?done=logs');
        exit;
    } elseif ($action === 'delete_orphans') {
        $dir = __DIR__ . '/../uploads/labels';
        foreach (find_orphan_photos($db) as $o) {
            @unlink($dir . '/' . $o['name']);
        }
        header('Location: /pages/maintenance.php?done=orphans');
        exit;
    } elseif ($action === 'purge_ips') {
        $db->exec('DELETE FROM login_attempts');
        header('Location: /pages/maintenance.php?done=ips');
        exit;
    } elseif ($action === 'unlock_ip') {
        $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$_POST['ip'] ?? '']);
        header('Location: /pages/maintenance.php?done=ip');
        exit;
    }
}

$aiLogCount = (int) $db->query('SELECT COUNT(*) FROM ai_enrichment_log')->fetchColumn();
$aiLogSize = (int) $db->query('SELECT COALESCE(SUM(LENGTH(COALESCE(prompt,"")) + LENGTH(COALESCE(raw_response,""))), 0) FROM ai_enrichment_log')->fetchColumn();
$orphans = find_orphan_photos($db);
$orphanSize = array_sum(array_column($orphans, 'size'));
$blockedIps = $db->query('SELECT * FROM login_attempts ORDER BY last_attempt DESC')->fetchAll();

$pageTitle = 'Maintenance';
$activeNav = 'settings';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <h1>Maintenance</h1>
    <div class="header-actions">
        <a href="/pages/settings.php" class="btn btn-ghost"><?= icon('settings', 16) ?> Paramètres</a>
    </div>
</div>

<?php if ($success === 'logs'): ?><div class="alert alert-success">Logs IA vidés.</div>
<?php elseif ($success === 'orphans'): ?><div class="alert alert-success">Photos orphelines supprimées.</div>
<?php elseif ($success === 'ips'): ?><div class="alert alert-success">Toutes les IP débloquées.</div>
<?php elseif ($success === 'ip'): ?><div class="alert alert-success">IP débloquée.</div>
<?php endif; ?>

<div class="grid grid-2 section">
    <div class="card">
        <div class="wine-detail-header" style="margin-bottom:0;">
            <h2>Logs IA</h2>
            <?php if ($aiLogCount > 0): ?><a href="/pages/ai_logs.php" class="btn btn-sm btn-ghost"><?= icon('eye', 14) ?> Voir le détail</a><?php endif; ?>
        </div>
        <p style="color:var(--text-muted);"><?= $aiLogCount ?> entrée(s) · <?= round($aiLogSize / 1024, 1) ?> Ko de texte.
        Ces logs servent au débogage des appels Gemini (prompt + réponse brute) : ils peuvent être vidés sans risque.</p>
        <?php if ($aiLogCount > 0): ?>
        <form method="post" style="margin-top:0.8rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_ai_logs">
            <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash', 15) ?> Vider les logs IA</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Photos orphelines</h2>
        <?php if (!$orphans): ?>
            <p style="color:var(--text-muted);">Aucune photo orpheline : toutes les images de uploads/labels/ sont rattachées à un vin.</p>
        <?php else: ?>
            <p style="color:var(--text-muted);"><?= count($orphans) ?> photo(s) sans vin associé · <?= round($orphanSize / 1024, 1) ?> Ko. Clique une photo pour l'agrandir.</p>
            <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin:0.6rem 0 0.8rem;">
                <?php foreach (array_slice($orphans, 0, 24) as $o): ?>
                    <div style="text-align:center;">
                        <?= wine_thumbnail_html('uploads/labels/' . $o['name'], 'orphan-thumb') ?>
                        <div style="color:var(--text-faint); font-size:0.7rem; max-width:70px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($o['name']) ?>"><?= round($o['size'] / 1024) ?> Ko</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($orphans) > 24): ?><p style="color:var(--text-muted); font-size:0.85rem;">… et <?= count($orphans) - 24 ?> autre(s).</p><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_orphans">
                <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash', 15) ?> Supprimer les photos orphelines</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card section">
    <div class="wine-detail-header" style="margin-bottom:0;">
        <h2>IP bloquées (connexion)</h2>
        <a href="/pages/security_log.php" class="btn btn-sm btn-ghost"><?= icon('shield', 14) ?> Journal détaillé</a>
    </div>
    <?php if (!$blockedIps): ?>
        <p style="color:var(--text-muted);">Aucune IP dans la liste des tentatives de connexion.</p>
    <?php else: ?>
    <table class="responsive-table">
        <thead><tr><th>Adresse IP</th><th>Tentatives</th><th>Dernière tentative</th><th>Bloquée jusqu'à</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($blockedIps as $row):
            $isLocked = $row['locked_until'] && strtotime($row['locked_until']) > time();
        ?>
            <tr>
                <td data-label="IP"><?= e($row['ip_address']) ?></td>
                <td data-label="Tentatives"><?= (int) $row['attempts'] ?></td>
                <td data-label="Dernière"><?= e($row['last_attempt']) ?></td>
                <td data-label="Bloquée jusqu'à"><?= $isLocked ? '<strong style="color:var(--danger);">' . e($row['locked_until']) . '</strong>' : '—' ?></td>
                <td data-label="">
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unlock_ip">
                        <input type="hidden" name="ip" value="<?= e($row['ip_address']) ?>">
                        <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?> Débloquer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge_ips">
        <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash', 15) ?> Tout purger</button>
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
