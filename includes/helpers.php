<?php

const ICONS = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'bottle' => '<path d="M10 2h4M10 2v4.5l-3.2 4.3A2 2 0 0 0 6 12v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-.8-1.2L14 6.5V2"/>',
    'crate' => '<rect x="3" y="7" width="18" height="14" rx="1"/><path d="M3 7l3-4h12l3 4"/><path d="M9 12v9M15 12v9M3 12h18"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    'chart' => '<path d="M4 20V10M12 20V4M20 20v-7"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
    'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>',
    'trash' => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/>',
    'eye' => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/>',
    'glass' => '<path d="M7 3h10l-1.2 9a3.8 3.8 0 0 1-3.8 3.4v0a3.8 3.8 0 0 1-3.8-3.4L7 3Z"/><path d="M12 15.4V21M8 21h8"/>',
    'download' => '<path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 19h16"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/>',
    'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',
    'rack' => '<rect x="3" y="4" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="16" width="18" height="4" rx="1"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M12 8a2.5 2.5 0 1 1-2.5-2.5C11.5 5.5 12 8 12 8ZM12 8a2.5 2.5 0 1 0 2.5-2.5C12.5 5.5 12 8 12 8Z"/>',
    'barcode' => '<path d="M3 5v14M7 5v14M10 5v10M10 18.5v.5M13 5v14M16.5 5v10M16.5 18.5v.5M21 5v14"/>',
];

/** Lettre d'une ligne : 1 => A, 2 => B, ... 26 => Z, puis AA, AB... */
function rack_row_letter(int $row): string
{
    $letters = '';
    while ($row > 0) {
        $row--;
        $letters = chr(65 + ($row % 26)) . $letters;
        $row = intdiv($row, 26);
    }
    return $letters;
}

/** Cache des réglages, partagé entre lecture et écriture (retourné par référence). */
function &setting_cache(): array
{
    static $cache = [];
    return $cache;
}

function get_setting(PDO $db, string $key, ?string $default = null): ?string
{
    $cache = &setting_cache();
    if (!array_key_exists($key, $cache)) {
        $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = $val !== false ? $val : null;
    }
    return $cache[$key] ?? $default;
}

function set_setting(PDO $db, string $key, ?string $value): void
{
    $db->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    )->execute(['k' => $key, 'v' => $value, 'v2' => $value]);

    // Sans cette ligne, toute lecture ultérieure dans la même requête
    // renverrait la valeur d'avant l'enregistrement.
    $cache = &setting_cache();
    $cache[$key] = $value;
}

const COLOR_THEMES = [
    'bordeaux' => ['label' => 'Bordeaux', 'bg' => '#14100f', 'accent' => '#8b1e3f', 'gold' => '#c9a227'],
    'bourgogne' => ['label' => 'Bourgogne', 'bg' => '#10150f', 'accent' => '#2f6b45', 'gold' => '#c9a227'],
    'nuit' => ['label' => 'Nuit', 'bg' => '#0d1220', 'accent' => '#3a5da8', 'gold' => '#d4af37'],
    'ardoise' => ['label' => 'Ardoise', 'bg' => '#1e1d1e', 'accent' => '#e2665c', 'gold' => '#b8894a'],
    'champagne' => ['label' => 'Champagne', 'bg' => '#f7f3ec', 'accent' => '#8b1e3f', 'gold' => '#9c7a15'],
];

function app_color_theme(): string
{
    $theme = get_setting(get_db(), 'color_theme', 'bordeaux');
    return array_key_exists($theme, COLOR_THEMES) ? $theme : 'bordeaux';
}

function drink_soon_threshold_years(): int
{
    $years = (int) get_setting(get_db(), 'drink_soon_threshold_years', '1');
    return $years >= 1 ? $years : 1;
}

/** Toutes les caves (rangements physiques), dans l'ordre d'affichage. */
function get_cellars(PDO $db): array
{
    return $db->query('SELECT * FROM cellars ORDER BY sort_order, id')->fetchAll();
}

/**
 * Crée une cave. Partagé entre la page Paramètres et la configuration du casier.
 * @return int id de la cave créée
 */
function create_cellar(PDO $db, array $data): int
{
    $maxOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM cellars')->fetchColumn();
    $db->prepare('INSERT INTO cellars (name, location, grid_rows, grid_cols, cell_capacity, col_labels, sort_order)
                  VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([
           trim($data['name']),
           trim($data['location'] ?? '') ?: null,
           max(1, min(26, (int) ($data['grid_rows'] ?? 3))),   // lignes = lettres A..Z
           max(1, min(99, (int) ($data['grid_cols'] ?? 6))),   // colonnes = chiffres 1..99
           max(1, (int) ($data['cell_capacity'] ?? 36)),
           trim($data['col_labels'] ?? '') ?: null,
           $maxOrder + 1,
       ]);
    $cellarId = (int) $db->lastInsertId();

    // Par défaut on remplit toute la grille : sans cela, une nouvelle cave
    // impose de créer et positionner chaque emplacement à la main.
    if (!array_key_exists('auto_locations', $data) || !empty($data['auto_locations'])) {
        generate_cellar_locations($db, $cellarId);
    }
    return $cellarId;
}

/**
 * Supprime une cave. Refuse si c'est la dernière ou si elle contient encore des
 * bouteilles ; les cases sont détachées et non supprimées.
 * @return array{ok:bool, error?:string}
 */
function delete_cellar(PDO $db, int $cellarId): array
{
    $nb = (int) $db->query('SELECT COUNT(*) FROM cellars')->fetchColumn();
    if ($nb <= 1) {
        return ['ok' => false, 'error' => 'Impossible de supprimer la dernière cave.'];
    }
    $stmt = $db->prepare('SELECT COALESCE(SUM(s.quantity), 0) FROM stock s
                          INNER JOIN storage_locations sl ON sl.id = s.storage_location_id
                          WHERE sl.cellar_id = ?');
    $stmt->execute([$cellarId]);
    $bottles = (int) $stmt->fetchColumn();
    if ($bottles > 0) {
        return ['ok' => false, 'error' => "Cette cave contient encore $bottles bouteille(s). Déplace-les avant de la supprimer."];
    }
    $db->prepare('UPDATE storage_locations SET cellar_id = NULL, rack_row = NULL, rack_col = NULL WHERE cellar_id = ?')
        ->execute([$cellarId]);
    $db->prepare('DELETE FROM cellars WHERE id = ?')->execute([$cellarId]);
    return ['ok' => true];
}

/** Caves avec leur nombre d'emplacements, de bouteilles et leur capacité totale. */
function cellars_with_stats(PDO $db): array
{
    return $db->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM storage_locations sl WHERE sl.cellar_id = c.id) AS case_count,
                (SELECT COALESCE(SUM(s.quantity), 0) FROM stock s
                 INNER JOIN storage_locations sl2 ON sl2.id = s.storage_location_id
                 WHERE sl2.cellar_id = c.id) AS bottle_count,
                (SELECT COALESCE(SUM(COALESCE(NULLIF(sl3.capacity, 0), c.cell_capacity)), 0)
                 FROM storage_locations sl3 WHERE sl3.cellar_id = c.id) AS total_capacity
         FROM cellars c ORDER BY c.sort_order, c.id'
    )->fetchAll();
}

/** Une cave par son id ; à défaut la première. Null si aucune cave. */
function get_cellar(PDO $db, ?int $id = null): ?array
{
    if ($id) {
        $stmt = $db->prepare('SELECT * FROM cellars WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }
    return $db->query('SELECT * FROM cellars ORDER BY sort_order, id LIMIT 1')->fetch() ?: null;
}

/**
 * En-tête d'une colonne : libellé personnalisé de la cave si défini
 * (ex. « Devant », « Derrière » pour une armoire), sinon le numéro 1, 2, 3...
 * Convention : lignes en lettres (A, B, C...), colonnes en chiffres.
 */
function cellar_column_label(array $cellar, int $col): string
{
    $labels = trim((string) ($cellar['col_labels'] ?? ''));
    if ($labels !== '') {
        $parts = array_map('trim', explode(',', $labels));
        if (($parts[$col - 1] ?? '') !== '') {
            return $parts[$col - 1];
        }
    }
    return (string) $col;
}

/**
 * Nom d'un emplacement d'après sa position : « A1 », « B12 »…
 * Si la cave nomme ses colonnes (armoire Devant/Derrière), on obtient « A Devant ».
 */
function cellar_position_label(array $cellar, int $row, int $col): string
{
    $colLabel = cellar_column_label($cellar, $col);
    $separator = ctype_digit($colLabel) ? '' : ' ';
    return rack_row_letter($row) . $separator . $colLabel;
}

/**
 * Crée les emplacements manquants d'une cave pour couvrir toute sa grille,
 * nommés d'après leur position. Évite la saisie manuelle case par case
 * (50 créations pour une armoire 5×10) et n'écrase jamais l'existant.
 *
 * @return int nombre d'emplacements créés
 */
function generate_cellar_locations(PDO $db, int $cellarId): int
{
    $cellar = get_cellar($db, $cellarId);
    if (!$cellar) {
        return 0;
    }
    $rows = (int) $cellar['grid_rows'];
    $cols = (int) $cellar['grid_cols'];

    // Positions déjà occupées : on ne recrée que ce qui manque.
    $stmt = $db->prepare('SELECT rack_row, rack_col FROM storage_locations
                          WHERE cellar_id = ? AND rack_row IS NOT NULL AND rack_col IS NOT NULL');
    $stmt->execute([$cellarId]);
    $taken = [];
    foreach ($stmt->fetchAll() as $t) {
        $taken[(int) $t['rack_row'] . '-' . (int) $t['rack_col']] = true;
    }

    $insert = $db->prepare('INSERT INTO storage_locations (cellar_id, name, rack_row, rack_col) VALUES (?, ?, ?, ?)');
    $created = 0;
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            if (isset($taken[$r . '-' . $c])) {
                continue;
            }
            $insert->execute([$cellarId, cellar_position_label($cellar, $r, $c), $r, $c]);
            $created++;
        }
    }
    return $created;
}

/**
 * Capacité réelle d'un emplacement : sa valeur propre si elle est renseignée,
 * sinon la capacité par défaut de la cave. Indispensable pour une armoire dont
 * chaque clayette accueille un nombre différent de bouteilles selon sa hauteur.
 */
function effective_cell_capacity(?array $cell, array $cellar): int
{
    if ($cell !== null && isset($cell['capacity']) && $cell['capacity'] !== null && (int) $cell['capacity'] > 0) {
        return (int) $cell['capacity'];
    }
    return (int) $cellar['cell_capacity'];
}

/**
 * Emplacements de toutes les caves, pour les listes déroulantes.
 * Triés dans l'ordre de la grille (ligne puis colonne) et non par nom : un tri
 * alphabétique placerait A10 avant A2, illisible sur une grande cave.
 */
function all_locations_for_select(PDO $db, bool $onlyAvailable = true): array
{
    // Capacité effective : celle de l'emplacement si définie, sinon celle de la
    // cave. NULL = aucune limite connue (emplacement hors cave).
    $sql =
        'SELECT sl.id, sl.name, sl.cellar_id, c.name AS cellar_name,
                COALESCE(SUM(s.quantity), 0) AS bottle_count,
                COALESCE(NULLIF(sl.capacity, 0), c.cell_capacity) AS capacity
         FROM storage_locations sl
         LEFT JOIN cellars c ON c.id = sl.cellar_id
         LEFT JOIN stock s ON s.storage_location_id = sl.id
         GROUP BY sl.id';
    if ($onlyAvailable) {
        // On écarte les emplacements pleins : les proposer n'aurait aucun sens
        // (Abbeville tient 1 bouteille par case, La Datcha 36).
        $sql .= ' HAVING capacity IS NULL OR bottle_count < capacity';
    }
    $sql .= ' ORDER BY c.sort_order, sl.rack_row IS NULL, sl.rack_row, sl.rack_col, sl.name';
    return $db->query($sql)->fetchAll();
}

/**
 * Place restante à un emplacement. NULL = pas de limite connue.
 */
function location_remaining_space(PDO $db, ?int $locationId): ?int
{
    if (!$locationId) {
        return null; // « Non assignée » n'a pas de capacité
    }
    $stmt = $db->prepare(
        'SELECT COALESCE(NULLIF(sl.capacity, 0), c.cell_capacity) AS capacity,
                COALESCE((SELECT SUM(quantity) FROM stock WHERE storage_location_id = sl.id), 0) AS used
         FROM storage_locations sl
         LEFT JOIN cellars c ON c.id = sl.cellar_id
         WHERE sl.id = ?'
    );
    $stmt->execute([$locationId]);
    $row = $stmt->fetch();
    if (!$row || $row['capacity'] === null) {
        return null;
    }
    return max(0, (int) $row['capacity'] - (int) $row['used']);
}

/**
 * Liste déroulante « cave » qui filtre une liste « emplacement » (JS).
 * Indispensable dès qu'une cave compte des dizaines d'emplacements : une liste
 * unique de toutes les caves devient vite inutilisable.
 */
function render_cellar_filter(PDO $db, string $targetSelectId): void
{
    $cellars = get_cellars($db);
    ?>
    <select class="cellar-filter" data-target="<?= e($targetSelectId) ?>" aria-label="Filtrer par cave" style="flex:0 0 auto; max-width:190px;">
        <option value="">Toutes les caves</option>
        <?php foreach ($cellars as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/**
 * Libellé « Cave — A1 ». Indispensable dès qu'il y a plusieurs caves : elles
 * ont toutes des emplacements homonymes (A1 existe partout).
 * Accepte les deux formes de lignes rencontrées : `name` (table des
 * emplacements) ou `location_name` (jointures depuis stock/historique).
 */
function location_full_label(array $loc): string
{
    $name = $loc['name'] ?? $loc['location_name'] ?? '';
    $cellar = $loc['cellar_name'] ?? null;
    return $cellar !== null && $cellar !== '' ? $cellar . ' — ' . $name : $name;
}

/**
 * Cases positionnées d'une cave, indexées [ligne][colonne].
 * @return array<int, array<int, array>>
 */
function cellar_grid(PDO $db, int $cellarId): array
{
    $stmt = $db->prepare(
        "SELECT sl.id, sl.name, sl.rack_row, sl.rack_col, sl.hard_access, sl.capacity, COALESCE(SUM(s.quantity), 0) AS bottle_count,
         SUBSTRING_INDEX(GROUP_CONCAT(w.label_photo_path ORDER BY (w.label_photo_path IS NULL), w.name SEPARATOR '||'), '||', 1) AS thumb_photo
         FROM storage_locations sl
         LEFT JOIN stock s ON s.storage_location_id = sl.id AND s.quantity > 0
         LEFT JOIN wines w ON w.id = s.wine_id
         WHERE sl.cellar_id = ? AND sl.rack_row IS NOT NULL AND sl.rack_col IS NOT NULL
         GROUP BY sl.id"
    );
    $stmt->execute([$cellarId]);
    $grid = [];
    foreach ($stmt->fetchAll() as $loc) {
        $grid[(int) $loc['rack_row']][(int) $loc['rack_col']] = $loc;
    }
    return $grid;
}

/** Compatibilité : réglages de la cave par défaut. */
function get_rack_settings(PDO $db): array
{
    $cellar = get_cellar($db);
    return $cellar ?: ['grid_rows' => 3, 'grid_cols' => 6, 'cell_capacity' => 36];
}

/**
 * Affiche une popup de sélection visuelle du casier : cliquer sur une case
 * met à jour le <select id="$selectId"> correspondant, puis ferme la popup.
 */
function render_rack_picker_modal(PDO $db, string $modalId, string $selectId): void
{
    $cellars = get_cellars($db);
    // On n'affiche que les caves ayant au moins une case positionnée.
    $grids = [];
    foreach ($cellars as $cellar) {
        $grid = cellar_grid($db, (int) $cellar['id']);
        if ($grid) {
            $grids[] = [$cellar, $grid];
        }
    }
    ?>
    <div class="modal-backdrop" id="<?= e($modalId) ?>">
        <div class="modal modal-lg">
            <h3>Choisir un emplacement</h3>
            <?php if (!$grids): ?>
                <p style="color:var(--text-muted);">Aucune case n'est encore positionnée. <a href="/pages/rack_config.php">Configure tes caves</a> pour choisir un emplacement visuellement.</p>
                <button type="button" class="btn btn-ghost rack-picker-close">Fermer</button>
            <?php else: ?>
            <?php foreach ($grids as [$cellar, $grid]):
                $rows = (int) $cellar['grid_rows'];
                $cols = (int) $cellar['grid_cols'];
            ?>
                <?php if (count($grids) > 1): ?>
                    <h4 style="margin:1rem 0 0.4rem;"><?= e($cellar['name']) ?><?= $cellar['location'] ? ' <span style="color:var(--text-muted); font-weight:400; font-size:0.88rem;">(' . e($cellar['location']) . ')</span>' : '' ?></h4>
                <?php endif; ?>
                <div class="rack-grid-wrap">
                <table class="rack-grid" data-target-select="<?= e($selectId) ?>" style="min-width:<?= 22 + $cols * 100 ?>px">
                    <thead>
                        <tr>
                            <th></th>
                            <?php for ($c = 1; $c <= $cols; $c++): ?><th><?= e(cellar_column_label($cellar, $c)) ?></th><?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for ($r = 1; $r <= $rows; $r++): ?>
                        <tr>
                            <th><?= e(rack_row_letter($r)) ?></th>
                            <?php for ($c = 1; $c <= $cols; $c++):
                                $cell = $grid[$r][$c] ?? null;
                                $cap = $cell ? effective_cell_capacity($cell, $cellar) : 0;
                                // Une case pleine ne peut plus rien accueillir :
                                // on la montre (pour se repérer) mais désactivée.
                                $isFull = $cell && $cap > 0 && (int) $cell['bottle_count'] >= $cap;
                            ?>
                                <td>
                                    <?php if ($cell): ?>
                                        <button type="button"
                                                class="rack-cell rack-picker-cell<?= !empty($cell['hard_access']) ? ' rack-cell-hard' : '' ?><?= $isFull ? ' rack-cell-full' : '' ?>"
                                                data-location-id="<?= (int) $cell['id'] ?>"
                                                data-location-name="<?= e($cell['name']) ?>"
                                                <?= $isFull ? 'disabled' : '' ?>
                                                title="<?= $isFull ? 'Emplacement plein' : (!empty($cell['hard_access']) ? 'Emplacement peu accessible' : e($cell['name'])) ?>">
                                            <span class="rack-cell-name"><?= e($cell['name']) ?></span>
                                            <span class="rack-cell-count"><?= (int) $cell['bottle_count'] ?> / <?= $cap ?></span>
                                        </button>
                                    <?php else: ?>
                                        <span class="rack-cell rack-cell-unassigned">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
                </div>
            <?php endforeach; ?>
            <button type="button" class="btn btn-ghost rack-picker-close" style="margin-top:1rem;">Fermer</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function wine_search_query(string $name, ?string $producer, ?int $vintage): string
{
    $parts = [$name];
    if ($producer && strcasecmp(trim($producer), trim($name)) !== 0) {
        $parts[] = $producer;
    }
    if ($vintage) {
        $parts[] = (string) $vintage;
    }
    return trim(implode(' ', $parts));
}

function vivino_search_url(string $name, ?string $producer, ?int $vintage): string
{
    return 'https://www.vivino.com/search/wines?q=' . urlencode(wine_search_query($name, $producer, $vintage));
}

function google_search_url(string $name, ?string $producer, ?int $vintage): string
{
    $query = wine_search_query($name, $producer, $vintage) . ' vin avis';
    return 'https://www.google.com/search?q=' . urlencode($query);
}

function icon(string $name, int $size = 18): string
{
    $inner = ICONS[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $inner . '</svg>';
}

const COLOR_LABELS = [
    'red' => 'Rouge',
    'white' => 'Blanc',
    'rose' => 'Rosé',
    'sparkling' => 'Effervescent',
    'sweet' => 'Moelleux/Liquoreux',
    'fortified' => 'Fortifié',
    'other' => 'Autre',
];

function color_label(string $color): string
{
    return COLOR_LABELS[$color] ?? $color;
}

function color_badge_class(string $color): string
{
    return 'badge-' . (array_key_exists($color, COLOR_LABELS) ? $color : 'other');
}

/**
 * @return array{status:string, label:string}
 */
function drink_status(?int $from, ?int $until): array
{
    $now = (int) date('Y');
    if ($from === null && $until === null) {
        return ['status' => 'unknown', 'label' => 'Fenêtre non renseignée'];
    }
    if ($from !== null && $now < $from) {
        return ['status' => 'too_early', 'label' => 'Encore trop jeune'];
    }
    if ($until !== null && $now > $until) {
        return ['status' => 'past', 'label' => 'Apogée dépassée'];
    }
    if ($until !== null && ($until - $now) <= drink_soon_threshold_years()) {
        return ['status' => 'drink_soon', 'label' => 'À boire bientôt'];
    }
    return ['status' => 'ready', 'label' => 'À son apogée'];
}

const CURRENCIES = [
    'EUR' => ['symbol' => '€', 'before' => false],
    'CHF' => ['symbol' => 'CHF', 'before' => false],
    'USD' => ['symbol' => '$', 'before' => true],
    'GBP' => ['symbol' => '£', 'before' => true],
];

function app_currency(): string
{
    $code = get_setting(get_db(), 'currency', 'EUR');
    return array_key_exists($code, CURRENCIES) ? $code : 'EUR';
}

function currency_symbol(): string
{
    return CURRENCIES[app_currency()]['symbol'];
}

function format_price(?float $price): string
{
    if ($price === null) {
        return '—';
    }
    $cur = CURRENCIES[app_currency()];
    $formatted = number_format($price, 2, ',', ' ');
    return $cur['before'] ? $cur['symbol'] . $formatted : $formatted . ' ' . $cur['symbol'];
}

/**
 * Enregistre une photo d'étiquette envoyée par l'utilisateur.
 * Partagé entre le formulaire du vin et la fiche détaillée, pour que la même
 * validation (type réel du fichier, taille, nom aléatoire) s'applique partout.
 *
 * @return string|null chemin relatif, ou null si aucun fichier valable
 */
function upload_label_photo(string $field = 'label_photo'): ?string
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($_FILES[$field]['size'] > 8 * 1024 * 1024) {
        return null;
    }
    // Type déterminé d'après le contenu réel, pas d'après l'extension annoncée.
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($_FILES[$field]['tmp_name']);
    if (!isset($allowed[$mime])) {
        return null;
    }
    $dir = __DIR__ . '/../uploads/labels/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $filename);
    return 'uploads/labels/' . $filename;
}

function wine_thumbnail_html(?string $photoPath, string $extraClass = ''): string
{
    $class = trim('thumb ' . $extraClass);
    if ($photoPath) {
        return '<img src="/' . e($photoPath) . '" class="' . e($class) . '" alt="">';
    }
    return '<div class="' . e($class) . ' thumb-placeholder"></div>';
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function total_stock_for_wine(PDO $db, int $wineId): int
{
    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock WHERE wine_id = ?');
    $stmt->execute([$wineId]);
    return (int) $stmt->fetchColumn();
}
