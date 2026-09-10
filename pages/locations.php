<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = $_POST['capacity'] !== '' ? (int) $_POST['capacity'] : null;
        if ($name === '') {
            $error = 'Le nom de la case est requis.';
        } else {
            $cellarId = $_POST['cellar_id'] !== '' ? (int) $_POST['cellar_id'] : null;
            $stmt = $db->prepare('INSERT INTO storage_locations (name, description, capacity, cellar_id, hard_access) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $description ?: null, $capacity, $cellarId, !empty($_POST['hard_access']) ? 1 : 0]);
            header('Location: /pages/locations.php');
            exit;
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = $_POST['capacity'] !== '' ? (int) $_POST['capacity'] : null;
        if ($name !== '') {
            $cellarId = $_POST['cellar_id'] !== '' ? (int) $_POST['cellar_id'] : null;
            $stmt = $db->prepare('UPDATE storage_locations SET name = ?, description = ?, capacity = ?, cellar_id = ?, hard_access = ? WHERE id = ?');
            $stmt->execute([$name, $description ?: null, $capacity, $cellarId, !empty($_POST['hard_access']) ? 1 : 0, $id]);
        }
        header('Location: /pages/locations.php');
        exit;
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('DELETE FROM storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: /pages/locations.php');
        exit;
    }
}

$locations = $db->query(
    'SELECT sl.*, c.name AS cellar_name, COALESCE(SUM(s.quantity), 0) AS bottle_count
     FROM storage_locations sl
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     LEFT JOIN stock s ON s.storage_location_id = sl.id
     GROUP BY sl.id
     ORDER BY c.sort_order, sl.name'
)->fetchAll();
$cellars = get_cellars($db);

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM storage_locations WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch();
}

$pageTitle = 'Cases de rangement';
$activeNav = 'locations';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <h1>Cases de rangement</h1>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card section">
    <h2><?= $editing ? 'Modifier la case' : 'Ajouter une case' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="field">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="Ex: Casier A, Étagère du bas...">
            </div>
            <div class="field">
                <label for="capacity">Capacité (bouteilles, optionnel)</label>
                <input type="number" id="capacity" name="capacity" min="0" value="<?= e((string) ($editing['capacity'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="cellar_id">Cave</label>
                <select id="cellar_id" name="cellar_id">
                    <option value="">— Non rattachée —</option>
                    <?php foreach ($cellars as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (string) ($editing['cellar_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="description">Description</label>
            <input type="text" id="description" name="description" value="<?= e($editing['description'] ?? '') ?>">
        </div>
        <div class="field">
            <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0;">
                <input type="checkbox" name="hard_access" value="1" <?= !empty($editing['hard_access']) ? 'checked' : '' ?>>
                Emplacement peu accessible (ex : bouteilles du fond)
            </label>
        </div>
        <button type="submit" class="btn btn-accent"><?= $editing ? icon('check') . ' Enregistrer' : icon('plus') . ' Ajouter' ?></button>
        <?php if ($editing): ?><a href="/pages/locations.php" class="btn btn-ghost">Annuler</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (!$locations): ?>
        <div class="empty-state">Aucune case créée pour l'instant.</div>
    <?php else: ?>
    <table class="responsive-table">
        <thead><tr><th>Nom</th><th>Cave</th><th>Description</th><th>Capacité</th><th>Bouteilles stockées</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($locations as $loc): ?>
            <tr>
                <td data-label="Nom"><?= e($loc['name']) ?></td>
                <td data-label="Cave"><?= $loc['cellar_name'] ? e($loc['cellar_name']) : '<span style="color:var(--text-muted);">— non rattachée —</span>' ?></td>
                <td data-label="Description"><?= e($loc['description'] ?? '—') ?></td>
                <td data-label="Capacité"><?= $loc['capacity'] !== null ? (int) $loc['capacity'] : '—' ?></td>
                <td data-label="Bouteilles"><?= (int) $loc['bottle_count'] ?></td>
                <td data-label="">
                    <a href="/pages/locations.php?edit=<?= (int) $loc['id'] ?>" class="btn btn-sm"><?= icon('edit', 15) ?> Modifier</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette case ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?= icon('trash', 15) ?> Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
