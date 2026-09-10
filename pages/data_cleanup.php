<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$success = $_GET['done'] ?? null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'rename_grape') {
        $id = (int) ($_POST['grape_id'] ?? 0);
        $newName = trim($_POST['new_name'] ?? '');
        if ($id && $newName !== '') {
            // Si le nouveau nom existe déjà (autre casse/orthographe), on fusionne.
            $stmt = $db->prepare('SELECT id FROM grape_varieties WHERE LOWER(name) = LOWER(?) AND id <> ?');
            $stmt->execute([$newName, $id]);
            $existingId = $stmt->fetchColumn();
            if ($existingId) {
                $db->prepare('INSERT IGNORE INTO wine_grape_varieties (wine_id, grape_variety_id)
                              SELECT wine_id, ? FROM wine_grape_varieties WHERE grape_variety_id = ?')
                    ->execute([$existingId, $id]);
                $db->prepare('DELETE FROM wine_grape_varieties WHERE grape_variety_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM grape_varieties WHERE id = ?')->execute([$id]);
                // On garde l'orthographe saisie comme référence
                $db->prepare('UPDATE grape_varieties SET name = ? WHERE id = ?')->execute([$newName, $existingId]);
                header('Location: /pages/data_cleanup.php?done=grape_merged');
            } else {
                $db->prepare('UPDATE grape_varieties SET name = ? WHERE id = ?')->execute([$newName, $id]);
                header('Location: /pages/data_cleanup.php?done=grape_renamed');
            }
            exit;
        }
    } elseif ($action === 'delete_grape') {
        $id = (int) ($_POST['grape_id'] ?? 0);
        $stmt = $db->prepare('SELECT COUNT(*) FROM wine_grape_varieties WHERE grape_variety_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $error = 'Ce cépage est encore utilisé par au moins un vin.';
        } else {
            $db->prepare('DELETE FROM grape_varieties WHERE id = ?')->execute([$id]);
            header('Location: /pages/data_cleanup.php?done=grape_deleted');
            exit;
        }
    } elseif ($action === 'rename_region') {
        $old = $_POST['old_region'] ?? '';
        $new = trim($_POST['new_name'] ?? '');
        if ($old !== '' && $new !== '') {
            $db->prepare('UPDATE wines SET region = ? WHERE region = ?')->execute([$new, $old]);
            header('Location: /pages/data_cleanup.php?done=region_renamed');
            exit;
        }
    }
}

$grapes = $db->query(
    'SELECT gv.id, gv.name, COUNT(wgv.wine_id) AS wine_count
     FROM grape_varieties gv
     LEFT JOIN wine_grape_varieties wgv ON wgv.grape_variety_id = gv.id
     GROUP BY gv.id ORDER BY gv.name'
)->fetchAll();

$regions = $db->query(
    "SELECT region, COUNT(*) AS wine_count FROM wines
     WHERE region IS NOT NULL AND region <> ''
     GROUP BY region ORDER BY region"
)->fetchAll();

$pageTitle = 'Cépages & régions';
$activeNav = 'settings';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <h1>Cépages &amp; régions</h1>
    <div class="header-actions">
        <a href="/pages/settings.php" class="btn btn-ghost"><?= icon('settings', 16) ?> Paramètres</a>
    </div>
</div>

<p style="color:var(--text-muted); margin-top:-0.5rem;">Renommer un cépage vers un nom déjà existant fusionne les deux (utile pour les doublons de type "Pinot gris" / "Pinot Gris" créés par l'IA).</p>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success === 'grape_merged'): ?><div class="alert alert-success">Cépages fusionnés.</div>
<?php elseif ($success === 'grape_renamed'): ?><div class="alert alert-success">Cépage renommé.</div>
<?php elseif ($success === 'grape_deleted'): ?><div class="alert alert-success">Cépage supprimé.</div>
<?php elseif ($success === 'region_renamed'): ?><div class="alert alert-success">Région renommée (fusion automatique si elle existait déjà).</div>
<?php endif; ?>

<div class="grid grid-2 section">
    <div class="card">
        <h2>Cépages</h2>
        <?php if (!$grapes): ?><div class="empty-state">Aucun cépage enregistré.</div><?php else: ?>
        <table class="responsive-table">
            <thead><tr><th>Cépage</th><th>Vins</th><th>Renommer / fusionner</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($grapes as $g): ?>
                <tr>
                    <td data-label="Cépage"><?= e($g['name']) ?></td>
                    <td data-label="Vins"><?= (int) $g['wine_count'] ?></td>
                    <td data-label="Renommer">
                        <form method="post" style="display:flex; gap:0.4rem; align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="rename_grape">
                            <input type="hidden" name="grape_id" value="<?= (int) $g['id'] ?>">
                            <input type="text" name="new_name" placeholder="Nouveau nom" style="width:auto; min-width:120px; padding:0.35rem 0.5rem; font-size:0.85rem;">
                            <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?></button>
                        </form>
                    </td>
                    <td data-label="">
                        <?php if ((int) $g['wine_count'] === 0): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_grape">
                            <input type="hidden" name="grape_id" value="<?= (int) $g['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?= icon('trash', 14) ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Régions</h2>
        <?php if (!$regions): ?><div class="empty-state">Aucune région renseignée.</div><?php else: ?>
        <table class="responsive-table">
            <thead><tr><th>Région</th><th>Vins</th><th>Renommer / fusionner</th></tr></thead>
            <tbody>
            <?php foreach ($regions as $r): ?>
                <tr>
                    <td data-label="Région"><?= e($r['region']) ?></td>
                    <td data-label="Vins"><?= (int) $r['wine_count'] ?></td>
                    <td data-label="Renommer">
                        <form method="post" style="display:flex; gap:0.4rem; align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="rename_region">
                            <input type="hidden" name="old_region" value="<?= e($r['region']) ?>">
                            <input type="text" name="new_name" placeholder="Nouveau nom" style="width:auto; min-width:120px; padding:0.35rem 0.5rem; font-size:0.85rem;">
                            <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
