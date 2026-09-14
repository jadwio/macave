<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();

$perPage = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) $db->query('SELECT COUNT(*) FROM ai_enrichment_log')->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    'SELECT l.*, w.name AS wine_name FROM ai_enrichment_log l
     LEFT JOIN wines w ON w.id = l.wine_id
     ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$TYPE_BADGES = [
    'text' => ['badge-other', 'Texte'],
    'photo' => ['badge-other', 'Photo'],
    'price_estimate' => ['badge-alert', 'Estimation prix (obsolète)'],
];

// Affiche un extrait ; le texte complet reste disponible via <details> (natif,
// donc accessible au clavier sans rien coder de plus).
function render_long_text(?string $text): string
{
    $text = $text ?? '';
    if ($text === '') {
        return '<span style="color:var(--text-faint);">—</span>';
    }
    $excerpt = mb_substr($text, 0, 160);
    if (mb_strlen($text) <= 160) {
        return '<span style="white-space:pre-wrap;">' . e($text) . '</span>';
    }
    return '<span style="white-space:pre-wrap;">' . e($excerpt) . '…</span>'
        . '<details style="margin-top:0.3rem;"><summary style="cursor:pointer; color:var(--gold); font-size:0.82rem;">Voir tout (' . mb_strlen($text) . ' car.)</summary>'
        . '<pre style="white-space:pre-wrap; font-family:inherit; font-size:0.85rem; margin:0.4rem 0 0; color:var(--text-muted);">' . e($text) . '</pre></details>';
}

$pageTitle = 'Logs IA';
$activeNav = 'settings';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <h1>Logs IA</h1>
    <div class="header-actions">
        <a href="/pages/maintenance.php" class="btn btn-ghost"><?= icon('settings', 16) ?> Maintenance</a>
    </div>
</div>
<p style="color:var(--text-muted); margin-top:-0.5rem;">
    Détail des appels à l'IA (Gemini) : prompt envoyé et réponse brute reçue. Sert au débogage — sans impact sur les vins de la cave.
</p>

<div class="card section">
    <?php if (!$rows): ?>
        <div class="empty-state">Aucun log IA pour l'instant.</div>
    <?php else: ?>
    <table class="responsive-table">
        <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Type</th>
                <th scope="col">Vin</th>
                <th scope="col">Prompt</th>
                <th scope="col">Réponse brute</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): [$badgeClass, $badgeLabel] = $TYPE_BADGES[$r['request_type']] ?? ['badge-other', $r['request_type']]; ?>
            <tr>
                <td data-label="Date"><?= e(date('d/m/Y H:i:s', strtotime($r['created_at']))) ?></td>
                <td data-label="Type"><span class="badge <?= $badgeClass ?>"><?= e($badgeLabel) ?></span></td>
                <td data-label="Vin"><?= $r['wine_name'] ? '<a href="/pages/wine_detail.php?id=' . (int) $r['wine_id'] . '">' . e($r['wine_name']) . '</a>' : '<span style="color:var(--text-faint);">—</span>' ?></td>
                <td data-label="Prompt" style="max-width:320px;"><?= render_long_text($r['prompt']) ?></td>
                <td data-label="Réponse brute" style="max-width:320px;"><?= render_long_text($r['raw_response']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <nav class="form-row" style="margin-top:1rem; justify-content:space-between; align-items:center;" aria-label="Pagination des logs IA">
        <?php if ($page > 1): ?>
            <a href="/pages/ai_logs.php?page=<?= $page - 1 ?>" class="btn btn-sm">&larr; Précédent</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <span style="color:var(--text-muted); font-size:0.85rem;">Page <?= $page ?> / <?= $totalPages ?> (<?= $total ?> entrées)</span>
        <?php if ($page < $totalPages): ?>
            <a href="/pages/ai_logs.php?page=<?= $page + 1 ?>" class="btn btn-sm">Suivant &rarr;</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
