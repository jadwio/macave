<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/ai_enrichment.php';

$db = get_db();
$errors = [];
$success = $_GET['saved'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, admin_password_hash($db))) {
            $errors[] = 'Mot de passe actuel incorrect.';
        } elseif (mb_strlen($new) < 8) {
            $errors[] = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        } elseif ($new !== $confirm) {
            $errors[] = 'La confirmation ne correspond pas au nouveau mot de passe.';
        } else {
            set_setting($db, 'admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            header('Location: /pages/settings.php?saved=password');
            exit;
        }
    } elseif ($action === 'save_theme') {
        $theme = $_POST['color_theme'] ?? 'bordeaux';
        set_setting($db, 'color_theme', array_key_exists($theme, COLOR_THEMES) ? $theme : 'bordeaux');
        header('Location: /pages/settings.php?saved=theme');
        exit;
    } elseif ($action === 'save_wine_settings') {
        $threshold = max(1, (int) ($_POST['drink_soon_threshold_years'] ?? 1));
        set_setting($db, 'drink_soon_threshold_years', (string) $threshold);
        $currency = $_POST['currency'] ?? 'EUR';
        set_setting($db, 'currency', array_key_exists($currency, CURRENCIES) ? $currency : 'EUR');
        header('Location: /pages/settings.php?saved=wine');
        exit;
    } elseif ($action === 'save_ai_key') {
        if (!empty($_POST['reset_ai_key'])) {
            set_setting($db, 'gemini_api_key', null);
        } else {
            $newKey = trim($_POST['gemini_api_key'] ?? '');
            if ($newKey !== '') {
                set_setting($db, 'gemini_api_key', $newKey);
            }
        }
        $model = trim($_POST['gemini_model'] ?? '');
        set_setting($db, 'gemini_model', $model !== '' ? $model : null);
        foreach (array_keys(GEMINI_TASKS) as $task) {
            if ($task === 'text') {
                continue; // porté par gemini_model
            }
            $val = trim($_POST['gemini_model_' . $task] ?? '');
            set_setting($db, 'gemini_model_' . $task, $val !== '' ? $val : null);
        }
        $quota = (int) ($_POST['gemini_daily_quota'] ?? 0);
        if ($quota > 0) {
            set_setting($db, 'gemini_daily_quota', (string) $quota);
        }
        header('Location: /pages/settings.php?saved=ai');
        exit;
    } elseif ($action === 'refresh_models') {
        gemini_list_models(true);
        header('Location: /pages/settings.php?saved=models');
        exit;
    } elseif ($action === 'apply_recommended_models') {
        $reco = gemini_recommended_assignment(gemini_list_models());
        foreach ($reco as $task => $modelName) {
            set_setting($db, $task === 'text' ? 'gemini_model' : 'gemini_model_' . $task, $modelName);
        }
        header('Location: /pages/settings.php?saved=reco');
        exit;
    } elseif ($action === 'backup_now') {
        $result = create_backup_zip($db);
        if ($result['ok']) {
            header('Location: /pages/settings.php?saved=backup');
        } else {
            $errors[] = $result['error'];
        }
        if (!$errors) {
            exit;
        }
    } elseif ($action === 'save_backup_email') {
        $email = trim($_POST['backup_email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse email invalide.';
        } else {
            set_setting($db, 'backup_email', $email !== '' ? $email : null);
            header('Location: /pages/settings.php?saved=backup_email');
            exit;
        }
    } elseif ($action === 'create_cellar') {
        if (trim($_POST['name'] ?? '') === '') {
            $errors[] = 'Le nom de la cave est requis.';
        } else {
            create_cellar($db, $_POST);
            header('Location: /pages/settings.php?saved=cellar_created');
            exit;
        }
    } elseif ($action === 'rename_cellar') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Le nom de la cave est requis.';
        } else {
            $db->prepare('UPDATE cellars SET name = ?, location = ? WHERE id = ?')
                ->execute([$name, trim($_POST['location'] ?? '') ?: null, (int) $_POST['cellar_id']]);
            header('Location: /pages/settings.php?saved=cellar_renamed');
            exit;
        }
    } elseif ($action === 'delete_cellar') {
        $result = delete_cellar($db, (int) $_POST['cellar_id']);
        if (!$result['ok']) {
            $errors[] = $result['error'];
        } else {
            header('Location: /pages/settings.php?saved=cellar_deleted');
            exit;
        }
    } elseif ($action === 'delete_backup') {
        $file = $_POST['file'] ?? '';
        if (preg_match('/^macave_backup_\d{8}_\d{6}\.zip$/', $file)) {
            @unlink(backups_dir() . '/' . $file);
        }
        header('Location: /pages/settings.php?saved=backup_deleted');
        exit;
    }
}

$currentThreshold = drink_soon_threshold_years();
$currentAiKeyOverride = get_setting($db, 'gemini_api_key');
$currentModelOverride = get_setting($db, 'gemini_model');
$activeModel = $currentModelOverride ?: GEMINI_MODEL;
$availableModels = gemini_list_models();
// Modèle configuré pour chaque tâche ('text' est porté par le réglage général)
$taskModels = [];
foreach (array_keys(GEMINI_TASKS) as $t) {
    $taskModels[$t] = $t === 'text' ? $currentModelOverride : get_setting($db, 'gemini_model_' . $t);
}
// Compté sur les modèles réellement utilisés (choix manuels + attribution auto)
$distinctModels = count(array_unique(gemini_task_models()));
$aiByModel = gemini_requests_today_by_model();
$aiUsedToday = array_sum($aiByModel);
$aiQuota = gemini_daily_quota();
$currentCurrency = app_currency();
$backupEmail = get_setting($db, 'backup_email');
$backups = list_backups();
$cellarList = cellars_with_stats($db);
$currentTheme = app_color_theme();
$cronUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'macave.famille-dumas.fr') . '/pages/cron_backup.php?token=' . backup_cron_token();

$pageTitle = 'Paramètres';
$activeNav = 'settings';
require __DIR__ . '/../includes/layout_header.php';
?>

<h1>Paramètres</h1>

<?php if ($errors): ?>
    <div class="alert alert-error"><?php foreach ($errors as $e): ?><?= e($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($success === 'password'): ?>
    <div class="alert alert-success">Mot de passe mis à jour.</div>
<?php elseif ($success === 'theme'): ?>
    <div class="alert alert-success">Thème appliqué.</div>
<?php elseif ($success === 'wine'): ?>
    <div class="alert alert-success">Réglages des vins enregistrés.</div>
<?php elseif ($success === 'ai'): ?>
    <div class="alert alert-success">Réglages IA mis à jour.</div>
<?php elseif ($success === 'models'): ?>
    <div class="alert alert-success">Liste des modèles rafraîchie depuis l'API Google.</div>
<?php elseif ($success === 'reco'): ?>
    <div class="alert alert-success">Répartition recommandée appliquée : chaque tâche a désormais son modèle dédié.</div>
<?php elseif ($success === 'backup'): ?>
    <div class="alert alert-success">Sauvegarde créée — télécharge-la ci-dessous.</div>
<?php elseif ($success === 'backup_email'): ?>
    <div class="alert alert-success">Email de sauvegarde enregistré.</div>
<?php elseif ($success === 'backup_deleted'): ?>
    <div class="alert alert-success">Sauvegarde supprimée.</div>
<?php elseif ($success === 'cellar_created'): ?>
    <div class="alert alert-success">Cave créée. Configure ses emplacements pour l'utiliser.</div>
<?php elseif ($success === 'cellar_renamed'): ?>
    <div class="alert alert-success">Cave renommée.</div>
<?php elseif ($success === 'cellar_deleted'): ?>
    <div class="alert alert-success">Cave supprimée. Ses emplacements ont été détachés, pas supprimés.</div>
<?php endif; ?>

<div class="card section">
    <h2>Mes caves</h2>
    <p style="color:var(--text-muted); font-size:0.9rem;">
        Déclare autant de rangements que nécessaire : casier, armoire à vin, carton de garde…
        Chacun a ses propres dimensions et sa propre capacité, et le stock reste consolidé.
    </p>

    <table class="responsive-table" style="margin-top:1rem;">
        <thead><tr><th colspan="2">Nom &amp; lieu (modifiables)</th><th>Grille</th><th>Emplacements</th><th>Remplissage</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cellarList as $c): ?>
            <tr>
                <td data-label="Nom" colspan="2">
                    <form method="post" class="form-row" style="gap:0.4rem; align-items:center; flex-wrap:nowrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rename_cellar">
                        <input type="hidden" name="cellar_id" value="<?= (int) $c['id'] ?>">
                        <input type="text" name="name" value="<?= e($c['name']) ?>" required style="min-width:130px;">
                        <input type="text" name="location" value="<?= e($c['location'] ?? '') ?>" placeholder="Lieu" style="min-width:110px;">
                        <button type="submit" class="btn btn-sm" title="Renommer"><?= icon('check', 14) ?></button>
                    </form>
                </td>
                <td data-label="Grille"><?= (int) $c['grid_rows'] ?> × <?= (int) $c['grid_cols'] ?><?= $c['col_labels'] ? ' <span style="color:var(--text-muted); font-size:0.85rem;">(' . e($c['col_labels']) . ')</span>' : '' ?></td>
                <td data-label="Emplacements"><?= (int) $c['case_count'] ?></td>
                <td data-label="Remplissage"><?= (int) $c['bottle_count'] ?> / <?= (int) $c['total_capacity'] ?></td>
                <td data-label="">
                    <a href="/pages/rack_config.php?cellar=<?= (int) $c['id'] ?>" class="btn btn-sm"><?= icon('settings', 14) ?> Configurer</a>
                    <?php if (count($cellarList) > 1 && (int) $c['bottle_count'] === 0): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_cellar">
                            <input type="hidden" name="cellar_id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?= icon('trash', 14) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 style="margin-top:1.5rem;">Ajouter une cave</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_cellar">
        <div class="form-row">
            <div class="field">
                <label for="cellar_name">Nom</label>
                <input type="text" id="cellar_name" name="name" required placeholder="Ex : Armoire à vin, Carton de garde">
            </div>
            <div class="field">
                <label for="cellar_location">Lieu (optionnel)</label>
                <input type="text" id="cellar_location" name="location" placeholder="Ex : Cuisine, maison de campagne">
            </div>
        </div>
        <div class="form-row">
            <div class="field"><label for="cellar_rows">Lignes / niveaux (A, B, C…)</label><input type="number" id="cellar_rows" name="grid_rows" min="1" max="26" value="3"></div>
            <div class="field"><label for="cellar_cols">Colonnes (1, 2, 3…)</label><input type="number" id="cellar_cols" name="grid_cols" min="1" max="99" value="6"></div>
            <div class="field">
                <label for="cellar_capacity">Capacité par emplacement</label>
                <input type="number" id="cellar_capacity" name="cell_capacity" min="1" value="12">
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;">
                    Bouteilles dans <em>une</em> case, pas dans toute la cave.
                </p>
            </div>
        </div>
        <div class="field">
            <label for="cellar_col_labels">Noms des colonnes (optionnel)</label>
            <input type="text" id="cellar_col_labels" name="col_labels" placeholder="Ex : Devant, Derrière">
            <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;">
                Séparés par des virgules. Vide = colonnes numérotées 1, 2, 3…
            </p>
        </div>
        <div class="field">
            <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0;">
                <input type="checkbox" name="auto_locations" value="1" checked>
                Créer automatiquement tous les emplacements de la grille
            </label>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;">
                Chaque case est créée et positionnée, nommée d'après sa position : A1, A2, B1…
                Décoche seulement si tu veux nommer et placer tes emplacements toi-même.
            </p>
        </div>
        <button type="submit" class="btn btn-accent"><?= icon('plus') ?> Créer la cave</button>
    </form>
</div>

<div class="card section">
    <h2>Apparence</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_theme">
        <div class="theme-grid">
            <?php foreach (COLOR_THEMES as $key => $t): ?>
                <label class="theme-option">
                    <input type="radio" name="color_theme" value="<?= e($key) ?>" <?= $currentTheme === $key ? 'checked' : '' ?> onchange="this.form.requestSubmit()">
                    <span class="theme-swatch" style="background:<?= e($t['bg']) ?>;">
                        <span class="theme-dots">
                            <span class="theme-dot" style="background:<?= e($t['accent']) ?>;"></span>
                            <span class="theme-dot" style="background:<?= e($t['gold']) ?>;"></span>
                        </span>
                        <span class="theme-name" style="color:<?= $key === 'champagne' ? '#2b211a' : '#f1e9e4' ?>;"><?= e($t['label']) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <noscript><button type="submit" class="btn btn-accent" style="margin-top:1rem;"><?= icon('check') ?> Appliquer</button></noscript>
    </form>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <h2>Sécurité — Changer le mot de passe</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label for="current_password">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label for="new_password">Nouveau mot de passe</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-accent"><?= icon('check') ?> Changer le mot de passe</button>
        </form>
    </div>

    <div class="card">
        <h2>Vins — Fenêtre de dégustation</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_wine_settings">
            <div class="field">
                <label for="drink_soon_threshold_years">Seuil "à boire bientôt" (années avant la fin de la fenêtre)</label>
                <input type="number" id="drink_soon_threshold_years" name="drink_soon_threshold_years" min="1" max="20" value="<?= (int) $currentThreshold ?>">
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.4rem;">
                    Un vin passe dans l'onglet "À boire bientôt" quand il reste ce nombre d'années (ou moins) avant la fin de sa fenêtre de dégustation.
                </p>
            </div>
            <div class="field">
                <label for="currency">Devise</label>
                <select id="currency" name="currency">
                    <?php foreach (CURRENCIES as $code => $cur): ?>
                        <option value="<?= e($code) ?>" <?= $currentCurrency === $code ? 'selected' : '' ?>><?= e($code) ?> (<?= e($cur['symbol']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.4rem;">
                    Change uniquement l'affichage : les montants déjà saisis ne sont pas convertis.
                </p>
            </div>
            <button type="submit" class="btn btn-accent"><?= icon('check') ?> Enregistrer</button>
        </form>
    </div>

    <div class="card">
        <h2>Intelligence artificielle</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_ai_key">
            <div class="field">
                <label for="gemini_api_key">Clé API Gemini (optionnel)</label>
                <input type="text" id="gemini_api_key" name="gemini_api_key" placeholder="<?= $currentAiKeyOverride ? 'Clé personnalisée active — laisser vide pour la conserver' : 'Clé par défaut du serveur utilisée' ?>" autocomplete="off">
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.4rem;">
                    Laisse ce champ vide pour ne rien changer. Renseigne une valeur pour la remplacer.
                </p>
            </div>
            <?php if ($currentAiKeyOverride): ?>
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.9rem; margin-bottom:1rem;">
                    <input type="checkbox" name="reset_ai_key" value="1"> Revenir à la clé par défaut du serveur
                </label>
            <?php endif; ?>
            <h3 style="margin-top:1.2rem;">Modèle par type d'appel</h3>
            <p style="color:var(--text-muted); font-size:0.85rem; margin-top:-0.3rem;">
                Le quota gratuit s'applique <strong>par modèle</strong> : attribuer un modèle différent à chaque
                tâche multiplie la capacité journalière. Laissé sur « Automatique », chaque tâche utilise déjà le
                modèle le plus adapté — c'est le réglage recommandé.
                <br>Actuellement : <strong><?= $distinctModels ?></strong> modèle(s) distinct(s) en usage,
                soit environ <strong><?= $distinctModels * $aiQuota ?></strong> requêtes/jour au total.
            </p>

            <?php foreach (GEMINI_TASKS as $task => $info):
                $fieldName = $task === 'text' ? 'gemini_model' : 'gemini_model_' . $task;
                $current = $taskModels[$task];
                $effective = gemini_model_for_task($task);
            ?>
                <div class="field">
                    <label for="<?= e($fieldName) ?>"><?= e($info[0]) ?></label>
                    <?php if ($availableModels): ?>
                        <select id="<?= e($fieldName) ?>" name="<?= e($fieldName) ?>">
                            <option value="">Automatique — <?= e($effective) ?></option>
                            <?php foreach ($availableModels as $mName => $mLabel): ?>
                                <option value="<?= e($mName) ?>" <?= $current === $mName ? 'selected' : '' ?>><?= e($mLabel) ?> — <?= e($mName) ?><?= str_contains($mName, 'latest') ? ' (suit les mises à jour)' : '' ?></option>
                            <?php endforeach; ?>
                            <?php if ($current && !isset($availableModels[$current])): ?>
                                <option value="<?= e($current) ?>" selected><?= e($current) ?> (personnalisé)</option>
                            <?php endif; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" id="<?= e($fieldName) ?>" name="<?= e($fieldName) ?>" value="<?= e($current ?? '') ?>" placeholder="Automatique : <?= e($effective) ?>" autocomplete="off">
                    <?php endif; ?>
                    <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;"><?= e($info[1]) ?></p>
                </div>
            <?php endforeach; ?>

            <div class="field">
                <label>Consommation IA du jour</label>
                <?php if (!$aiByModel): ?>
                    <p style="color:var(--text-muted); font-size:0.9rem;">Aucun appel IA depuis minuit.</p>
                <?php else: ?>
                    <?php foreach ($aiByModel as $mName => $count):
                        $pct = $aiQuota > 0 ? min(100, round($count / $aiQuota * 100)) : 0;
                        $color = $pct >= 90 ? 'var(--danger)' : ($pct >= 60 ? 'var(--gold)' : 'var(--success)');
                    ?>
                        <div style="margin-bottom:0.7rem;">
                            <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.25rem;">
                                <span><?= e($mName) ?></span>
                                <span><strong><?= $count ?></strong> / <?= $aiQuota ?> · <?= max(0, $aiQuota - $count) ?> restante(s)</span>
                            </div>
                            <div style="background:var(--bg-elevated); border-radius:999px; height:8px; overflow:hidden;">
                                <div style="height:8px; border-radius:999px; width:<?= $pct ?>%; background:<?= $color ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div style="font-size:0.9rem; color:var(--text-muted); border-top:1px solid var(--border); padding-top:0.5rem;">
                        Total toutes requêtes : <strong><?= $aiUsedToday ?></strong>
                    </div>
                <?php endif; ?>
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.4rem;">
                    Appels réussis depuis minuit, ventilés par modèle réellement servi par Google — c'est à ce
                    niveau que s'applique le quota. Les refus pour quota dépassé ne sont pas comptés : ils ne
                    consomment rien. Le compteur se remet à zéro à minuit ici, la remise à zéro côté Google suit
                    son propre fuseau.
                </p>
            </div>

            <div class="field">
                <label for="gemini_daily_quota">Quota journalier du modèle</label>
                <input type="number" id="gemini_daily_quota" name="gemini_daily_quota" min="1" max="100000" value="<?= (int) $aiQuota ?>">
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.4rem;">
                    Sert uniquement à calculer la jauge ci-dessus. Valeur constatée pour <?= e(GEMINI_MODEL) ?> : 20 requêtes/jour.
                </p>
            </div>

            <button type="submit" class="btn btn-accent"><?= icon('check') ?> Enregistrer</button>
        </form>
        <div class="form-row" style="margin-top:0.6rem;">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply_recommended_models">
                <button type="submit" class="btn btn-sm"><?= icon('check', 14) ?> Figer la répartition recommandée</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="refresh_models">
                <button type="submit" class="btn btn-sm btn-ghost"><?= icon('search', 14) ?> Rafraîchir la liste des modèles</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Raccourcis</h2>
        <p style="color:var(--text-muted); font-size:0.9rem;">Autres réglages et outils de l'application.</p>
        <div class="form-row" style="margin-top:1rem;">
            <a href="/pages/rack.php" class="btn"><?= icon('rack', 16) ?> Voir mes caves</a>
            <a href="/pages/data_cleanup.php" class="btn"><?= icon('edit', 16) ?> Cépages &amp; régions</a>
            <a href="/pages/maintenance.php" class="btn"><?= icon('settings', 16) ?> Maintenance</a>
            <a href="/pages/security_log.php" class="btn"><?= icon('shield', 16) ?> Journal de sécurité</a>
            <a href="/pages/export.php" class="btn"><?= icon('download', 16) ?> Export CSV</a>
        </div>
    </div>
</div>

<div class="card section">
    <h2>Sauvegardes</h2>
    <p style="color:var(--text-muted); font-size:0.9rem;">
        Chaque sauvegarde contient la base de données complète (fiches, stock, historique) et toutes les photos d'étiquettes,
        dans un fichier ZIP. Les 5 plus récentes sont conservées sur le serveur.
    </p>

    <div class="form-row" style="margin-top:1rem; align-items:flex-end;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="backup_now">
            <button type="submit" class="btn btn-accent"><?= icon('download', 16) ?> Sauvegarder maintenant</button>
        </form>
        <form method="post" class="form-row" style="align-items:flex-end; flex:1;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_backup_email">
            <div class="field" style="margin-bottom:0; flex:1;">
                <label for="backup_email">Email de réception (sauvegarde automatique)</label>
                <input type="email" id="backup_email" name="backup_email" value="<?= e($backupEmail ?? '') ?>" placeholder="Laisser vide pour ne pas envoyer d'email">
            </div>
            <button type="submit" class="btn"><?= icon('check', 15) ?> Enregistrer</button>
        </form>
    </div>

    <?php if ($backups): ?>
    <table class="responsive-table" style="margin-top:1.25rem;">
        <thead><tr><th>Fichier</th><th>Date</th><th>Taille</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
            <tr>
                <td data-label="Fichier"><?= e($b['name']) ?></td>
                <td data-label="Date"><?= date('d/m/Y H:i', $b['mtime']) ?></td>
                <td data-label="Taille"><?= round($b['size'] / 1048576, 2) ?> Mo</td>
                <td data-label="">
                    <a href="/pages/backup_download.php?file=<?= e($b['name']) ?>" class="btn btn-sm"><?= icon('download', 14) ?> Télécharger</a>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_backup">
                        <input type="hidden" name="file" value="<?= e($b['name']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?= icon('trash', 14) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem;">Sauvegarde automatique planifiée</h3>
    <p style="color:var(--text-muted); font-size:0.9rem;">
        Configure une tâche cron dans le panneau IONOS (ou un service gratuit comme cron-job.org) qui appelle cette URL
        à la fréquence voulue (ex: chaque dimanche à 3h). La sauvegarde sera créée sur le serveur et envoyée à l'email
        ci-dessus s'il est renseigné.
    </p>
    <input type="text" readonly value="<?= e($cronUrl) ?>" onclick="this.select()" style="margin-top:0.5rem; font-size:0.8rem; color:var(--text-muted);">
</div>

<div class="card section" id="changelog">
    <h2>Journal des versions <span class="badge badge-success">v<?= e(APP_VERSION) ?></span></h2>
    <p style="color:var(--text-muted); font-size:0.9rem;">Historique des évolutions de l'application, de la plus récente à la plus ancienne.</p>
    <?php foreach (CHANGELOG as $i => $entry): ?>
        <details class="changelog-entry" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <strong>v<?= e($entry['version']) ?></strong> — <?= e($entry['title']) ?>
                <span class="changelog-date"><?= e(format_date($entry['date'])) ?></span>
            </summary>
            <ul>
                <?php foreach ($entry['items'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
