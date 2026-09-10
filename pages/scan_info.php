<?php
// Mode magasin : scanner ou saisir un vin pour obtenir une synthèse complète
// avant achat (prix indicatif, garde, description...). Rien n'est enregistré en cave.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/scan_history.php';

$db = get_db();
$scanHistory = get_scan_history($db, 40);
$SCAN_TYPE_LABELS = ['photo' => 'Photo', 'barcode' => 'Code-barres', 'qr' => 'QR code', 'name' => 'Recherche'];

$pageTitle = 'Scanner un vin';
$activeNav = 'scan';
require __DIR__ . '/../includes/layout_header.php';
?>

<h1>Scanner un vin en magasin</h1>
<p style="color:var(--text-muted); margin-top:-0.5rem;">
    Photographie l'étiquette, scanne le code-barres ou le QR code (ou saisis le nom) pour obtenir une synthèse
    avant achat : prix indicatif, potentiel de garde, style, accords. <strong>Rien n'est ajouté à ta cave.</strong>
</p>

<div class="card section">
    <h2>Photo de l'étiquette</h2>
    <p style="color:var(--text-muted); font-size:0.88rem;">
        La méthode la plus fiable en rayon : elle fonctionne sur n'importe quelle bouteille,
        même sans code-barres exploitable.
    </p>
    <!-- capture="environment" ouvre directement l'appareil photo arrière sur mobile -->
    <input type="file" id="si-photo-camera" accept="image/*" capture="environment" style="display:none;">
    <input type="file" id="si-photo-gallery" accept="image/png,image/jpeg,image/webp" style="display:none;">
    <div class="form-row">
        <button type="button" id="si-btn-camera" class="btn btn-accent"><?= icon('camera') ?> Prendre une photo</button>
        <button type="button" id="si-btn-gallery" class="btn"><?= icon('image') ?> Choisir une image</button>
    </div>
    <img id="si-photo-preview" alt="" style="display:none; max-width:220px; margin-top:0.9rem; border-radius:8px; border:1px solid var(--border);">
</div>

<div class="grid grid-2 section">
    <div class="card">
        <h2>Code-barres ou QR code</h2>
        <button type="button" id="si-scan-btn" class="btn btn-accent"><?= icon('barcode') ?> Activer la caméra</button>
        <video id="si-video" autoplay playsinline muted style="display:none; width:100%; max-height:240px; border-radius:8px; background:#000; object-fit:cover; margin-top:0.8rem;"></video>
        <div class="form-row" style="margin-top:0.8rem; align-items:flex-end;">
            <div class="field" style="flex:1; margin-bottom:0;">
                <label for="si-ean">Ou saisir le code EAN</label>
                <input type="text" id="si-ean" inputmode="numeric" placeholder="8 ou 13 chiffres">
            </div>
            <button type="button" id="si-ean-btn" class="btn btn-sm"><?= icon('search', 14) ?> OK</button>
        </div>
        <p style="color:var(--text-muted); font-size:0.8rem; margin-top:0.6rem;">
            Recherche via Open Food Facts : couvre surtout les vins de grande distribution.
            Si le code est inconnu, utilise la recherche par nom.
        </p>
    </div>

    <div class="card">
        <h2>Ou par nom</h2>
        <div class="field">
            <label for="si-name">Nom du vin</label>
            <input type="text" id="si-name" placeholder="Ex : Château Talbot">
        </div>
        <div class="form-row" style="align-items:flex-end;">
            <div class="field" style="flex:1;">
                <label for="si-vintage">Millésime (optionnel)</label>
                <input type="number" id="si-vintage" min="1900" max="2100" placeholder="Ex : 2019">
            </div>
            <div class="field">
                <button type="button" id="si-analyze-btn" class="btn btn-accent"><?= icon('search', 15) ?> Analyser</button>
            </div>
        </div>
    </div>
</div>

<div class="card section">
    <h2>Domaine ou appellation</h2>
    <p style="color:var(--text-muted); font-size:0.88rem;">
        Pour se renseigner sur un domaine, un château ou une appellation entière (et non une bouteille précise).
        Source principale : Wikipédia, complétée par l'IA.
    </p>
    <div class="form-row" style="align-items:flex-end;">
        <div class="field" style="flex:1; margin-bottom:0;">
            <label for="si-domain">Domaine / appellation</label>
            <input type="text" id="si-domain" placeholder="Ex : Pomerol, Domaine Leflaive">
        </div>
        <button type="button" id="si-domain-btn" class="btn btn-accent"><?= icon('search', 15) ?> Rechercher</button>
    </div>
    <div id="si-domain-status" class="ai-status"></div>
    <div id="si-domain-result" style="display:none; margin-top:1rem; border-top:1px solid var(--border); padding-top:1rem;">
        <h3 id="si-d-name"></h3>
        <div id="si-d-meta" style="color:var(--text-muted); font-size:0.88rem; margin-bottom:0.6rem;"></div>
        <p id="si-d-description"></p>
        <div id="si-d-sources" style="color:var(--text-muted); font-size:0.83rem;"></div>
    </div>
</div>

<div id="si-status" class="ai-status" style="margin-bottom:1rem;"></div>

<div class="card section" id="si-result" style="display:none;">
    <div class="wine-detail-header" style="margin-bottom:0.75rem;">
        <div>
            <h2 id="si-r-name" style="margin-bottom:0.2rem;"></h2>
            <div id="si-r-sub" style="color:var(--text-muted); font-size:0.9rem;"></div>
        </div>
        <div class="header-actions">
            <a href="#" id="si-add-link" class="btn btn-accent"><?= icon('plus', 16) ?> Ajouter à ma cave</a>
        </div>
    </div>

    <div class="grid grid-3 section" style="margin-bottom:1rem;">
        <div class="card stat-tile">
            <div class="value" id="si-r-price" style="font-size:1.4rem;">—</div>
            <div class="label">Prix boutique indicatif</div>
        </div>
        <div class="card stat-tile">
            <div class="value" id="si-r-garde" style="font-size:1.15rem;">—</div>
            <div class="label" id="si-r-window">Potentiel de garde</div>
        </div>
        <div class="card stat-tile">
            <div class="value" id="si-r-color" style="font-size:1.15rem;">—</div>
            <div class="label" id="si-r-region">Type</div>
        </div>
    </div>

    <div id="si-r-garde-advice" style="padding:0.7rem 0.9rem; border:1px solid var(--gold); border-radius:8px; margin-bottom:1rem; display:none;"></div>

    <h3>Synthèse de dégustation</h3>
    <p id="si-r-description" style="margin-bottom:1rem;"></p>

    <div id="si-r-reputation-wrap" style="display:none;">
        <h3>Réputation &amp; niveau de gamme</h3>
        <p id="si-r-reputation" style="margin-bottom:1rem;"></p>
    </div>

    <div id="si-r-grapes-wrap" style="display:none;">
        <h3>Cépages</h3>
        <p id="si-r-grapes" style="margin-bottom:1rem;"></p>
    </div>

    <div id="si-r-pairing-wrap" style="display:none;">
        <h3>Accords mets-vin</h3>
        <p id="si-r-pairing" style="margin-bottom:1rem;"></p>
    </div>

    <p style="color:var(--text-muted); font-size:0.8rem; border-top:1px solid var(--border); padding-top:0.8rem;">
        Synthèse générée par IA à partir de connaissances générales — les prix sont des estimations approximatives, pas des données temps réel.
    </p>
</div>

<div class="card section">
    <h2>Historique des scans</h2>
    <p style="color:var(--text-muted); font-size:0.88rem;">
        Chaque vin identifié ici est gardé en mémoire, avec le lieu si tu autorises la géolocalisation —
        pratique pour retrouver le magasin où tu l'as vu.
    </p>
    <div id="scan-history-empty" class="empty-state" style="<?= $scanHistory ? 'display:none;' : '' ?>">
        Aucun scan enregistré pour l'instant.
    </div>
    <div id="scan-history-list" class="scan-history-list">
        <?php foreach ($scanHistory as $h): ?>
            <?php
            $metaParts = array_filter([
                $h['producer'] ?? null,
                $h['region'] ?? null,
                ($h['price_low'] && $h['price_high']) ? format_price((float) $h['price_low']) . ' – ' . format_price((float) $h['price_high']) : null,
            ]);
            ?>
            <?php
            $addParams = ['prefill_name' => $h['wine_name']];
            if ($h['producer']) $addParams['prefill_producer'] = $h['producer'];
            if ($h['vintage']) $addParams['prefill_vintage'] = $h['vintage'];
            ?>
            <div class="scan-history-item" data-id="<?= (int) $h['id'] ?>">
                <?= wine_thumbnail_html($h['photo_path'] ?? null) ?>
                <div class="scan-history-info">
                    <div class="scan-history-name"><?= e($h['wine_name']) ?><?= $h['vintage'] ? ' ' . (int) $h['vintage'] : '' ?></div>
                    <?php if ($metaParts): ?>
                        <div class="scan-history-meta"><?= e(implode(' · ', $metaParts)) ?></div>
                    <?php endif; ?>
                    <div class="scan-history-date">
                        <?= e($SCAN_TYPE_LABELS[$h['scan_type']] ?? $h['scan_type']) ?> · <?= e(date('d/m/Y H:i', strtotime($h['created_at']))) ?>
                        <?php if ($h['latitude'] !== null && $h['longitude'] !== null): ?>
                            · <a href="<?= e(maps_link_url((float) $h['latitude'], (float) $h['longitude'])) ?>" target="_blank" rel="noopener noreferrer">📍 <?= e($h['location_label'] ?: 'Voir sur la carte') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="scan-history-actions">
                    <?php if ($h['details_json']): ?>
                        <button type="button" class="btn btn-sm scan-history-view"
                                data-name="<?= e($h['wine_name']) ?>" data-vintage="<?= (int) ($h['vintage'] ?? 0) ?>"
                                data-details="<?= e($h['details_json']) ?>">Voir la fiche</button>
                    <?php endif; ?>
                    <a href="/pages/wine_form.php?<?= e(http_build_query($addParams)) ?>" class="btn btn-sm btn-accent">Ajouter à ma cave</a>
                    <button type="button" class="btn btn-sm btn-ghost scan-history-delete" data-id="<?= (int) $h['id'] ?>" title="Supprimer cette entrée"><?= icon('trash', 14) ?></button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    'use strict';
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const statusEl = document.getElementById('si-status');
    const video = document.getElementById('si-video');
    let stream = null;
    let scanning = false;
    let detector = null;
    let lastProducer = '';
    let currentScanType = 'name';

    function stopCamera() {
        scanning = false;
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
        video.style.display = 'none';
        document.getElementById('si-scan-btn').textContent = 'Activer la caméra';
    }

    // QR codes : EAN direct, lien GS1 (EAN embarqué) ou URL (étiquette électronique / site producteur)
    function handleScannedCode(raw) {
        raw = raw.trim();
        if (/^(\d{8}|\d{13})$/.test(raw)) { currentScanType = 'barcode'; lookupEan(raw); return; }
        const gs1 = raw.match(/\/01\/(\d{13,14})(\/|\?|$)/);
        if (gs1) {
            let gtin = gs1[1];
            if (gtin.length === 14) gtin = gtin.slice(1);
            currentScanType = 'barcode';
            lookupEan(gtin);
            return;
        }
        if (/^https?:\/\//i.test(raw)) { currentScanType = 'qr'; resolveQrUrl(raw); return; }
        statusEl.textContent = 'Code lu mais contenu non reconnu : ' + raw.slice(0, 60);
        if (stream) { scanning = true; scanLoop(); }
    }

    async function resolveQrUrl(url) {
        statusEl.textContent = 'QR code lu — analyse de la page liée...';
        try {
            const res = await fetch('/pages/qr_resolve.php?url=' + encodeURIComponent(url));
            const json = await res.json();
            if (!json.ok) {
                statusEl.innerHTML = 'QR code lu, mais impossible d\'en extraire le vin. ';
                const a = document.createElement('a');
                a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.textContent = 'Ouvrir le lien';
                statusEl.appendChild(a);
                if (stream) { scanning = true; scanLoop(); }
                return;
            }
            stopCamera();
            document.getElementById('si-name').value = json.data.title;
            lastProducer = '';
            analyze();
        } catch (err) {
            statusEl.textContent = 'Erreur réseau lors de l\'analyse du QR code.';
            if (stream) { scanning = true; scanLoop(); }
        }
    }

    async function scanLoop() {
        if (!scanning || !detector) return;
        try {
            if (video.readyState >= 2) {
                const codes = await detector.detect(video);
                if (codes.length) {
                    scanning = false;
                    handleScannedCode(codes[0].rawValue);
                    return;
                }
            }
        } catch (e) { /* frame illisible */ }
        setTimeout(scanLoop, 300);
    }

    document.getElementById('si-scan-btn').addEventListener('click', async function () {
        if (stream) { stopCamera(); return; }
        if (!('BarcodeDetector' in window)) {
            statusEl.textContent = 'Scanner caméra non supporté par ce navigateur — saisis le code EAN ou le nom.';
            return;
        }
        try {
            detector = detector || new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            video.style.display = 'block';
            this.textContent = 'Arrêter la caméra';
            statusEl.textContent = 'Vise le code-barres ou le QR code...';
            scanning = true;
            scanLoop();
        } catch (err) {
            statusEl.textContent = 'Accès caméra impossible — saisis le code EAN ou le nom.';
        }
    });

    async function lookupEan(ean) {
        statusEl.textContent = 'Recherche du code ' + ean + '...';
        try {
            const res = await fetch('/pages/barcode_lookup.php?ean=' + encodeURIComponent(ean));
            const json = await res.json();
            if (!json.ok) {
                statusEl.textContent = json.error || 'Produit introuvable.';
                if (stream) { scanning = true; scanLoop(); }
                return;
            }
            stopCamera();
            document.getElementById('si-name').value = json.data.name || '';
            lastProducer = json.data.producer || '';
            analyze();
        } catch (err) {
            statusEl.textContent = 'Erreur réseau lors de la recherche du code.';
            if (stream) { scanning = true; scanLoop(); }
        }
    }

    document.getElementById('si-ean-btn').addEventListener('click', function () {
        const ean = document.getElementById('si-ean').value.replace(/\D/g, '');
        if (!/^(\d{8}|\d{13})$/.test(ean)) {
            statusEl.textContent = 'Code EAN invalide (8 ou 13 chiffres).';
            return;
        }
        currentScanType = 'barcode';
        lookupEan(ean);
    });

    // --- Analyse à partir d'une photo d'étiquette ---
    ['si-btn-camera:si-photo-camera', 'si-btn-gallery:si-photo-gallery'].forEach(function (pair) {
        const parts = pair.split(':');
        const btn = document.getElementById(parts[0]);
        const input = document.getElementById(parts[1]);
        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) analyzePhoto(input.files[0]);
        });
    });

    async function analyzePhoto(file) {
        stopCamera(); // le scanner code-barres et la photo ne servent pas en même temps
        const preview = document.getElementById('si-photo-preview');
        preview.src = URL.createObjectURL(file);
        preview.style.display = 'block';

        statusEl.textContent = 'Lecture de l\'étiquette et analyse en cours...';
        document.getElementById('si-result').style.display = 'none';
        const prog = window.aiProgress ? window.aiProgress(statusEl) : null;
        try {
            const form = new FormData();
            form.append('photo', file);
            const res = await fetch('/pages/wine_info_photo.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: form,
            });
            const json = await res.json();
            if (!json.ok) {
                statusEl.textContent = 'Erreur IA : ' + (json.error || 'inconnue');
                if (prog) prog.fail();
                return;
            }
            const d = json.data;
            if (d.found === false || !d.name) {
                statusEl.textContent = 'Étiquette illisible ou vin non identifiable. Reprends la photo de plus près, ou saisis le nom.';
                if (prog) prog.fail();
                return;
            }
            // Le nom lu sur l'étiquette alimente aussi les champs de recherche
            document.getElementById('si-name').value = d.name;
            if (d.vintage) document.getElementById('si-vintage').value = d.vintage;

            renderResult(d.name, d.vintage || '', d, 'photo', file);
            statusEl.textContent = '';
            if (prog) prog.finish();
        } catch (err) {
            statusEl.textContent = 'Erreur réseau lors de l\'analyse de la photo.';
            if (prog) prog.fail();
        }
    }

    document.getElementById('si-analyze-btn').addEventListener('click', function () { currentScanType = 'name'; lastProducer = ''; analyze(); });
    document.getElementById('si-name').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { currentScanType = 'name'; lastProducer = ''; analyze(); }
    });

    const COLOR_LABELS = { red: 'Rouge', white: 'Blanc', rose: 'Rosé', sparkling: 'Effervescent', sweet: 'Moelleux', fortified: 'Fortifié', other: 'Vin' };

    async function analyze() {
        const name = document.getElementById('si-name').value.trim();
        const vintage = document.getElementById('si-vintage').value.trim();
        if (!name) {
            statusEl.textContent = 'Renseigne le nom du vin (ou scanne un code-barres).';
            return;
        }
        statusEl.textContent = 'Analyse du vin en cours...';
        document.getElementById('si-result').style.display = 'none';
        const prog = window.aiProgress ? window.aiProgress(statusEl) : null;
        try {
            const res = await fetch('/pages/wine_info.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ name: name, producer: lastProducer, vintage: vintage }),
            });
            const json = await res.json();
            if (!json.ok) {
                statusEl.textContent = 'Erreur IA : ' + (json.error || 'inconnue');
                if (prog) prog.fail();
                return;
            }
            renderResult(name, vintage, json.data, currentScanType);
            statusEl.textContent = '';
            if (prog) prog.finish();
        } catch (err) {
            statusEl.textContent = 'Erreur réseau lors de l\'analyse.';
            if (prog) prog.fail();
        }
    }

    function renderResult(name, vintage, d, scanType, photoFile) {
        if (d.found === false) {
            statusEl.textContent = 'L\'IA ne connaît pas ce vin assez précisément pour donner une synthèse fiable.';
            return;
        }
        displayResult(name, vintage, d);
        saveScanHistory(scanType || 'name', name, vintage, d, photoFile || null);
    }

    // Affichage pur de la fiche de synthèse, sans enregistrement — réutilisé pour
    // rouvrir une fiche déjà en historique (bouton « Voir la fiche »).
    function displayResult(name, vintage, d) {
        document.getElementById('si-r-name').textContent = name + (vintage ? ' ' + vintage : '');
        const subParts = [];
        if (d.producer && d.producer !== name) subParts.push(d.producer);
        if (d.appellation) subParts.push(d.appellation);
        if (d.country) subParts.push(d.country);
        document.getElementById('si-r-sub').textContent = subParts.join(' · ');

        document.getElementById('si-r-price').textContent =
            (d.price_low_eur && d.price_high_eur) ? d.price_low_eur + ' – ' + d.price_high_eur + ' €' : '—';

        document.getElementById('si-r-garde').textContent =
            d.is_vin_de_garde === true ? 'Vin de garde' : (d.is_vin_de_garde === false ? 'À boire jeune' : '—');
        document.getElementById('si-r-window').textContent =
            (d.drink_from_year && d.drink_until_year) ? 'Fenêtre : ' + d.drink_from_year + ' – ' + d.drink_until_year : 'Potentiel de garde';

        document.getElementById('si-r-color').textContent = COLOR_LABELS[d.color] || '—';
        document.getElementById('si-r-region').textContent = d.region || 'Type';

        const advice = document.getElementById('si-r-garde-advice');
        if (d.garde_advice) { advice.textContent = '🕐 ' + d.garde_advice; advice.style.display = 'block'; }
        else { advice.style.display = 'none'; }

        document.getElementById('si-r-description').textContent = d.description || 'Pas de description disponible.';

        toggleSection('si-r-reputation-wrap', 'si-r-reputation', d.reputation);
        toggleSection('si-r-grapes-wrap', 'si-r-grapes', (d.grape_varieties || []).join(', '));
        toggleSection('si-r-pairing-wrap', 'si-r-pairing', d.food_pairing);

        const params = new URLSearchParams({ prefill_name: name });
        if (d.producer) params.set('prefill_producer', d.producer);
        if (vintage) params.set('prefill_vintage', vintage);
        document.getElementById('si-add-link').href = '/pages/wine_form.php?' + params.toString();

        document.getElementById('si-result').style.display = 'block';
        document.getElementById('si-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // --- Historique des scans, avec géolocalisation optionnelle ---
    function getGeolocation() {
        return new Promise(function (resolve) {
            if (!navigator.geolocation) { resolve(null); return; }
            navigator.geolocation.getCurrentPosition(
                function (pos) { resolve({ lat: pos.coords.latitude, lon: pos.coords.longitude }); },
                function () { resolve(null); },
                { timeout: 8000, maximumAge: 300000 }
            );
        });
    }

    async function saveScanHistory(scanType, name, vintage, d, photoFile) {
        const geo = await getGeolocation();
        const form = new FormData();
        form.append('scan_type', scanType);
        form.append('wine_name', name);
        if (vintage) form.append('vintage', vintage);
        if (d.producer) form.append('producer', d.producer);
        if (d.region) form.append('region', d.region);
        if (d.color) form.append('color', d.color);
        if (d.price_low_eur) form.append('price_low', d.price_low_eur);
        if (d.price_high_eur) form.append('price_high', d.price_high_eur);
        if (geo) { form.append('latitude', geo.lat); form.append('longitude', geo.lon); }
        if (photoFile) form.append('photo', photoFile);
        // Encodé en base64 : envoyé tel quel, ce champ JSON (accolades, deux-points)
        // est silencieusement vidé en cours de route par le pare-feu applicatif de l'hébergeur.
        form.append('details_json', btoa(unescape(encodeURIComponent(JSON.stringify(d)))));
        try {
            const res = await fetch('/pages/scan_history_save.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: form,
            });
            const json = await res.json();
            if (json.ok && json.entry) prependHistoryEntry(json.entry);
        } catch (err) { /* l'historique est un bonus, jamais bloquant pour l'analyse */ }
    }

    const SCAN_TYPE_LABELS = { photo: 'Photo', barcode: 'Code-barres', qr: 'QR code', name: 'Recherche' };

    function prependHistoryEntry(entry) {
        document.getElementById('scan-history-empty').style.display = 'none';
        const list = document.getElementById('scan-history-list');
        const item = document.createElement('div');
        item.className = 'scan-history-item';
        item.dataset.id = entry.id;

        let thumb;
        if (entry.photo_path) {
            thumb = document.createElement('img');
            thumb.className = 'thumb';
            thumb.src = '/' + entry.photo_path;
        } else {
            thumb = document.createElement('div');
            thumb.className = 'thumb thumb-placeholder';
        }
        item.appendChild(thumb);

        const info = document.createElement('div');
        info.className = 'scan-history-info';

        const nameEl = document.createElement('div');
        nameEl.className = 'scan-history-name';
        nameEl.textContent = entry.wine_name + (entry.vintage ? ' ' + entry.vintage : '');
        info.appendChild(nameEl);

        const metaParts = [entry.producer, entry.region].filter(Boolean);
        if (entry.price_low && entry.price_high) metaParts.push(entry.price_low + ' – ' + entry.price_high + ' €');
        if (metaParts.length) {
            const metaEl = document.createElement('div');
            metaEl.className = 'scan-history-meta';
            metaEl.textContent = metaParts.join(' · ');
            info.appendChild(metaEl);
        }

        const dateEl = document.createElement('div');
        dateEl.className = 'scan-history-date';
        const now = new Date();
        const dateStr = String(now.getDate()).padStart(2, '0') + '/' + String(now.getMonth() + 1).padStart(2, '0') + '/' + now.getFullYear()
            + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        dateEl.textContent = (SCAN_TYPE_LABELS[entry.scan_type] || entry.scan_type) + ' · ' + dateStr;
        if (entry.latitude && entry.longitude) {
            dateEl.appendChild(document.createTextNode(' · '));
            const a = document.createElement('a');
            a.href = 'https://www.google.com/maps?q=' + entry.latitude + ',' + entry.longitude;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = '📍 ' + (entry.location_label || 'Voir sur la carte');
            dateEl.appendChild(a);
        }
        info.appendChild(dateEl);
        item.appendChild(info);

        const actions = document.createElement('div');
        actions.className = 'scan-history-actions';

        if (entry.details_json) {
            const view = document.createElement('button');
            view.type = 'button';
            view.className = 'btn btn-sm scan-history-view';
            view.textContent = 'Voir la fiche';
            view.dataset.name = entry.wine_name;
            view.dataset.vintage = entry.vintage || '';
            view.dataset.details = entry.details_json;
            actions.appendChild(view);
        }

        const addParams = new URLSearchParams({ prefill_name: entry.wine_name });
        if (entry.producer) addParams.set('prefill_producer', entry.producer);
        if (entry.vintage) addParams.set('prefill_vintage', entry.vintage);
        const add = document.createElement('a');
        add.className = 'btn btn-sm btn-accent';
        add.href = '/pages/wine_form.php?' + addParams.toString();
        add.textContent = 'Ajouter à ma cave';
        actions.appendChild(add);

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-ghost scan-history-delete';
        del.title = 'Supprimer cette entrée';
        del.dataset.id = entry.id;
        del.innerHTML = <?= json_encode(icon('trash', 14)) ?>;
        actions.appendChild(del);

        item.appendChild(actions);

        list.insertBefore(item, list.firstChild);
    }

    document.getElementById('scan-history-list').addEventListener('click', async function (e) {
        const viewBtn = e.target.closest('.scan-history-view');
        if (viewBtn) {
            try {
                const d = JSON.parse(viewBtn.dataset.details);
                displayResult(viewBtn.dataset.name, viewBtn.dataset.vintage, d);
            } catch (err) { /* fiche corrompue, on ignore */ }
            return;
        }
        const btn = e.target.closest('.scan-history-delete');
        if (!btn) return;
        if (!confirm('Supprimer cette entrée de l\'historique ?')) return;
        const id = btn.dataset.id;
        const form = new FormData();
        form.append('action', 'delete');
        form.append('id', id);
        try {
            const res = await fetch('/pages/scan_history_save.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: form,
            });
            const json = await res.json();
            if (json.ok) {
                const item = btn.closest('.scan-history-item');
                item.remove();
                if (!document.getElementById('scan-history-list').children.length) {
                    document.getElementById('scan-history-empty').style.display = 'block';
                }
            }
        } catch (err) { /* rien à faire */ }
    });

    // --- Branche « recherche domaine/appellation » du WineResolver ---
    const domainStatus = document.getElementById('si-domain-status');

    async function searchDomain() {
        const q = document.getElementById('si-domain').value.trim();
        if (!q) {
            domainStatus.textContent = 'Renseigne un domaine ou une appellation.';
            return;
        }
        domainStatus.textContent = 'Recherche en cours...';
        document.getElementById('si-domain-result').style.display = 'none';
        const prog = window.aiProgress ? window.aiProgress(domainStatus) : null;
        try {
            const res = await fetch('/pages/wine_resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ domain: q }),
            });
            const json = await res.json();
            if (!json.ok) {
                domainStatus.textContent = json.error || 'Aucun résultat.';
                if (prog) prog.fail();
                return;
            }
            const w = json.wine;
            document.getElementById('si-d-name').textContent = w.name || q;
            const meta = [];
            if (w.region) meta.push(w.region);
            if (w.appellation && w.appellation !== w.region) meta.push(w.appellation);
            if (w.country) meta.push(w.country);
            if (w.color) meta.push(COLOR_LABELS[w.color] || w.color);
            if (w.grape_varieties && w.grape_varieties.length) meta.push(w.grape_varieties.join(', '));
            document.getElementById('si-d-meta').textContent = meta.join(' · ');
            document.getElementById('si-d-description').textContent = w.description || 'Pas de description disponible.';
            document.getElementById('si-d-sources').textContent = 'Sources : ' + (json.sources || []).join(', ');
            document.getElementById('si-domain-result').style.display = 'block';
            domainStatus.textContent = '';
            if (prog) prog.finish();
        } catch (err) {
            domainStatus.textContent = 'Erreur réseau lors de la recherche.';
            if (prog) prog.fail();
        }
    }

    document.getElementById('si-domain-btn').addEventListener('click', searchDomain);
    document.getElementById('si-domain').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') searchDomain();
    });

    function toggleSection(wrapId, textId, value) {
        const wrap = document.getElementById(wrapId);
        if (value) {
            document.getElementById(textId).textContent = value;
            wrap.style.display = 'block';
        } else {
            wrap.style.display = 'none';
        }
    }
})();
</script>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
