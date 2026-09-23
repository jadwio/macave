(function () {
    'use strict';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Réduit une image avant envoi à l'IA — évite de dépasser le délai réseau
    // sur une photo de téléphone brute. Partagé par le scanner et l'outil de
    // recadrage d'étiquette.
    window.downscaleImage = function (file, maxDim, quality) {
        return new Promise(function (resolve) {
            if (!/^image\//.test(file.type) || file.type === 'image/gif') { resolve(file); return; }
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                const longEdge = Math.max(img.naturalWidth, img.naturalHeight) || maxDim;
                const scale = Math.min(1, maxDim / longEdge);
                if (scale === 1 && file.size < 700 * 1024) { resolve(file); return; }
                try {
                    const cv = document.createElement('canvas');
                    cv.width = Math.round(img.naturalWidth * scale);
                    cv.height = Math.round(img.naturalHeight * scale);
                    cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
                    cv.toBlob(function (blob) {
                        resolve(blob && blob.size < file.size ? blob : file);
                    }, 'image/jpeg', quality);
                } catch (e) { resolve(file); }
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    };

    // --- Outil de recadrage d'étiquette : suggestion IA ajustable à la main,
    // ou recadrage entièrement manuel. Utilisé partout où une photo d'étiquette
    // est choisie (ajout/édition d'un vin, remplacement depuis sa fiche).
    // Résout avec { file, skipped } (skipped = photo entière conservée telle
    // quelle) ou null si l'utilisateur annule la sélection.
    window.openCropTool = function (file, csrf) {
        return new Promise(function (resolve) {
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop open';
            backdrop.innerHTML =
                '<div class="modal modal-lg">' +
                '<h3>Ajuster le cadrage de l\'étiquette</h3>' +
                '<p style="color:var(--text-muted); font-size:0.85rem;">Fais glisser le cadre ou ses coins pour ajuster, puis valide.</p>' +
                '<div class="crop-wrap">' +
                '<img class="crop-img" alt="">' +
                '<div class="crop-box">' +
                '<div class="crop-handle crop-h-nw"></div><div class="crop-handle crop-h-ne"></div>' +
                '<div class="crop-handle crop-h-sw"></div><div class="crop-handle crop-h-se"></div>' +
                '</div>' +
                '<div class="crop-loading">Suggestion de cadrage en cours…</div>' +
                '</div>' +
                '<div class="form-row" style="margin-top:1rem; justify-content:flex-end;">' +
                '<button type="button" class="btn btn-ghost crop-cancel">Annuler</button>' +
                '<button type="button" class="btn crop-full">Utiliser la photo entière</button>' +
                '<button type="button" class="btn btn-accent crop-confirm">Valider le recadrage</button>' +
                '</div></div>';
            document.body.appendChild(backdrop);

            const img = backdrop.querySelector('.crop-img');
            const wrap = backdrop.querySelector('.crop-wrap');
            const box = backdrop.querySelector('.crop-box');
            const loading = backdrop.querySelector('.crop-loading');
            const objUrl = URL.createObjectURL(file);
            img.src = objUrl;

            let frac = { x: 0.1, y: 0.1, w: 0.8, h: 0.8 };
            let done = false;

            function applyFrac() {
                box.style.left = (frac.x * img.clientWidth) + 'px';
                box.style.top = (frac.y * img.clientHeight) + 'px';
                box.style.width = (frac.w * img.clientWidth) + 'px';
                box.style.height = (frac.h * img.clientHeight) + 'px';
            }

            function cleanup(result) {
                if (done) return;
                done = true;
                URL.revokeObjectURL(objUrl);
                window.removeEventListener('resize', applyFrac);
                backdrop.remove();
                resolve(result);
            }

            img.onload = function () {
                applyFrac();
                // Suggestion IA en second plan : n'écrase pas un cadre déjà ajusté à la main.
                (async function () {
                    let touched = false;
                    box.addEventListener('pointerdown', function onFirstTouch() {
                        touched = true;
                        box.removeEventListener('pointerdown', onFirstTouch);
                    });
                    try {
                        const small = await window.downscaleImage(file, 1280, 0.8);
                        const form = new FormData();
                        form.append('photo', small, 'photo.jpg');
                        const res = await fetch('/pages/detect_label_box.php', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': csrf },
                            body: form,
                        });
                        const json = await res.json();
                        if (json.ok && json.box && !touched) {
                            frac = json.box;
                            applyFrac();
                        }
                    } catch (e) { /* le cadre par défaut reste utilisable */ }
                    if (loading) loading.style.display = 'none';
                })();
            };

            let drag = null;
            wrap.addEventListener('pointerdown', function (e) {
                if (e.target === box) {
                    drag = { mode: 'move', startX: e.clientX, startY: e.clientY, orig: Object.assign({}, frac) };
                    box.setPointerCapture(e.pointerId);
                } else if (e.target.classList.contains('crop-handle')) {
                    const m = e.target.className.match(/crop-h-(\w+)/);
                    drag = { mode: m[1], startX: e.clientX, startY: e.clientY, orig: Object.assign({}, frac) };
                    e.target.setPointerCapture(e.pointerId);
                }
            });
            wrap.addEventListener('pointermove', function (e) {
                if (!drag) return;
                const dxF = (e.clientX - drag.startX) / img.clientWidth;
                const dyF = (e.clientY - drag.startY) / img.clientHeight;
                const o = drag.orig;
                const n = Object.assign({}, o);
                if (drag.mode === 'move') {
                    n.x = o.x + dxF;
                    n.y = o.y + dyF;
                } else {
                    if (drag.mode.includes('n')) { n.y = o.y + dyF; n.h = o.h - dyF; }
                    if (drag.mode.includes('s')) { n.h = o.h + dyF; }
                    if (drag.mode.includes('w')) { n.x = o.x + dxF; n.w = o.w - dxF; }
                    if (drag.mode.includes('e')) { n.w = o.w + dxF; }
                }
                n.w = Math.max(0.05, n.w);
                n.h = Math.max(0.05, n.h);
                n.x = Math.max(0, Math.min(n.x, 1 - n.w));
                n.y = Math.max(0, Math.min(n.y, 1 - n.h));
                n.w = Math.min(n.w, 1 - n.x);
                n.h = Math.min(n.h, 1 - n.y);
                frac = n;
                applyFrac();
            });
            wrap.addEventListener('pointerup', function () { drag = null; });
            wrap.addEventListener('pointercancel', function () { drag = null; });
            window.addEventListener('resize', applyFrac);

            backdrop.querySelector('.crop-cancel').addEventListener('click', function () { cleanup(null); });
            backdrop.querySelector('.crop-full').addEventListener('click', function () { cleanup({ file: file, skipped: true }); });
            backdrop.querySelector('.crop-confirm').addEventListener('click', function () {
                const nw = img.naturalWidth, nh = img.naturalHeight;
                const sx = Math.round(frac.x * nw), sy = Math.round(frac.y * nh);
                const sw = Math.round(frac.w * nw), sh = Math.round(frac.h * nh);
                const cv = document.createElement('canvas');
                cv.width = sw;
                cv.height = sh;
                cv.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);
                cv.toBlob(function (blob) {
                    cleanup(blob ? { file: blob, skipped: false } : { file: file, skipped: true });
                }, 'image/jpeg', 0.9);
            });
        });
    };

    // Ouvre l'outil de recadrage dès qu'une photo d'étiquette est choisie dans
    // `input`, et remplace son fichier par le résultat avant que quoi que ce
    // soit d'autre ne s'en serve (aperçu, envoi IA, soumission du formulaire).
    function attachCropOnSelect(input, filenameEl, onDone) {
        input.addEventListener('change', async function () {
            const file = input.files[0];
            if (!file) return;
            let result;
            try {
                result = await window.openCropTool(file, csrfToken());
            } catch (e) {
                result = { file: file, skipped: true }; // dégradation propre : photo non recadrée
            }
            if (result === null) {
                input.value = '';
                if (filenameEl) filenameEl.textContent = '';
                return;
            }
            try {
                const dt = new DataTransfer();
                dt.items.add(new File([result.file], file.name || 'label.jpg', { type: result.file.type || file.type || 'image/jpeg' }));
                input.files = dt.files;
            } catch (e) { /* navigateur trop ancien pour DataTransfer : on garde le fichier d'origine tel quel */ }
            if (filenameEl) filenameEl.textContent = result.skipped ? ('Photo sélectionnée : ' + file.name) : 'Photo recadrée prête à l\'envoi.';
            if (onDone) onDone();
        });
    }

    // --- Envoi manuel d'une étiquette depuis la fiche du vin ---
    const btnPickLabel = document.getElementById('btn-pick-label');
    const labelPhotoInputDetail = document.getElementById('label-photo-input');
    if (btnPickLabel && labelPhotoInputDetail) {
        btnPickLabel.addEventListener('click', function () {
            labelPhotoInputDetail.click();
        });
        attachCropOnSelect(labelPhotoInputDetail, document.getElementById('label-file-name'), function () {
            const nameEl = document.getElementById('label-file-name');
            if (nameEl) nameEl.textContent = 'Envoi en cours...';
            document.getElementById('upload-label-form').submit();
        });
    }

    // --- Recherche d'étiquette en ligne (proposition, sans enregistrement) ---
    const btnSearchLabel = document.getElementById('btn-search-label');
    if (btnSearchLabel) {
        btnSearchLabel.addEventListener('click', async function () {
            const status = document.getElementById('label-search-status');
            const list = document.getElementById('label-candidates');
            list.innerHTML = '';
            status.textContent = 'Recherche en cours...';
            btnSearchLabel.disabled = true;
            const prog = window.aiProgress ? window.aiProgress(status) : null;
            try {
                const res = await fetch('/pages/label_search.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                    body: JSON.stringify({ wine_id: btnSearchLabel.getAttribute('data-wine-id') }),
                });
                const json = await res.json();
                if (!json.ok) {
                    status.textContent = json.error || 'Recherche impossible.';
                    if (prog) prog.fail();
                    return;
                }
                if (!json.candidates.length) {
                    status.textContent = json.message || 'Aucune étiquette trouvée.';
                    if (prog) prog.finish();
                    return;
                }
                status.textContent = 'Choisis l\'étiquette à conserver — rien n\'est enregistré avant ta validation.';
                json.candidates.forEach(function (c) { list.appendChild(buildCandidate(c)); });
                if (prog) prog.finish();
            } catch (e) {
                status.textContent = 'Erreur réseau lors de la recherche.';
                if (prog) prog.fail();
            } finally {
                btnSearchLabel.disabled = false;
            }
        });

        function buildCandidate(c) {
            const card = document.createElement('div');
            card.className = 'label-candidate';

            const img = document.createElement('img');
            img.src = c.thumb;
            img.alt = c.title || '';
            img.title = 'Cliquer pour agrandir';
            // L'agrandissement montre l'image en pleine résolution, pas la
            // vignette : c'est ce qui permet de juger avant de valider.
            img.dataset.full = c.url;
            // Pas de chargement différé : ces vignettes sont affichées suite à
            // une action explicite, elles doivent apparaître immédiatement.
            img.addEventListener('error', function () {
                img.replaceWith(Object.assign(document.createElement('div'), {
                    className: 'label-candidate-error',
                    textContent: 'Aperçu indisponible',
                }));
            });
            card.appendChild(img);

            const title = document.createElement('div');
            title.className = 'label-candidate-title';
            title.textContent = c.title || '(sans titre)';
            card.appendChild(title);

            if (c.note) {
                const note = document.createElement('div');
                note.className = 'label-candidate-note';
                note.textContent = c.note;
                card.appendChild(note);
            }

            const credit = document.createElement('div');
            credit.className = 'label-candidate-credit';
            credit.textContent = c.credit;
            card.appendChild(credit);

            const zoom = document.createElement('button');
            zoom.type = 'button';
            zoom.className = 'btn btn-sm btn-ghost';
            zoom.textContent = 'Agrandir';
            zoom.addEventListener('click', function () { img.click(); });
            card.appendChild(zoom);

            const use = document.createElement('button');
            use.type = 'button';
            use.className = 'btn btn-sm btn-accent';
            use.textContent = 'Utiliser cette étiquette';
            use.addEventListener('click', function () {
                if (!confirm('Enregistrer cette étiquette pour ce vin ?')) return;
                document.getElementById('accept-label-url').value = c.url;
                document.getElementById('accept-label-form').submit();
            });
            card.appendChild(use);

            return card;
        }
    }

    // --- Listes liées : choisir une cave filtre ses emplacements ---
    // On reconstruit la liste au lieu de masquer les <option> : masquer une
    // option n'est pas fiable selon les navigateurs (ignoré sur mobile).
    document.querySelectorAll('.cellar-filter').forEach(function (filter) {
        const target = document.getElementById(filter.getAttribute('data-target'));
        if (!target) return;

        // Instantané de toutes les options d'origine
        const allOptions = Array.from(target.options).map(function (o) {
            return { value: o.value, label: o.textContent, cellar: o.getAttribute('data-cellar') || '' };
        });

        function rebuild(cellarId) {
            const previous = target.value;
            target.innerHTML = '';
            allOptions.forEach(function (o) {
                // L'option vide (« Non assignée ») reste toujours proposée
                if (cellarId && o.value !== '' && o.cellar !== cellarId) return;
                const opt = document.createElement('option');
                opt.value = o.value;
                // Une fois filtré sur une cave, le préfixe « Cave — » est redondant
                opt.textContent = cellarId ? o.label.replace(/^.*?\s—\s/, '') : o.label;
                opt.setAttribute('data-cellar', o.cellar);
                target.appendChild(opt);
            });
            // On conserve la sélection si elle existe encore après filtrage
            if (Array.from(target.options).some(function (o) { return o.value === previous; })) {
                target.value = previous;
            }
        }

        filter.addEventListener('change', function () { rebuild(filter.value); });

        // Permet au sélecteur visuel de casier de lever le filtre avant d'écrire
        // sa valeur : sans cela, choisir une case d'une autre cave échouerait,
        // l'option n'étant pas présente dans la liste filtrée.
        target.showAllCellars = function () {
            filter.value = '';
            rebuild('');
        };

        // Si un emplacement est déjà sélectionné, on positionne le filtre dessus
        const selected = allOptions.find(function (o) { return o.value === target.value && o.value !== ''; });
        if (selected && selected.cellar) {
            filter.value = selected.cellar;
            rebuild(selected.cellar);
        }

        // Le sélecteur visuel de casier écrit directement dans la liste cible :
        // on réaligne le filtre pour rester cohérent.
        target.addEventListener('change', function () {
            const opt = target.options[target.selectedIndex];
            const cellar = opt ? opt.getAttribute('data-cellar') : '';
            if (cellar && filter.value !== cellar) {
                filter.value = cellar;
                rebuild(cellar);
            }
        });
    });

    // --- Installation sur l'écran d'accueil (PWA) ---
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () { /* pas bloquant */ });
        });
    }

    (function initInstallBanner() {
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true; // iOS
        if (isStandalone) return; // déjà installée : rien à proposer

        if (localStorage.getItem('installBannerDismissed') === '1') return;

        const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
        let deferredPrompt = null;

        function showBanner(onInstallClick) {
            const banner = document.createElement('div');
            banner.className = 'install-banner';
            banner.innerHTML =
                '<span class="install-banner-text"></span>' +
                '<button type="button" class="btn btn-sm btn-accent install-banner-action">Installer</button>' +
                '<button type="button" class="btn btn-sm btn-ghost install-banner-close" aria-label="Fermer">' + iconX() + '</button>';
            banner.querySelector('.install-banner-text').textContent = isIos
                ? 'Ajoute Ma Cave à ton écran d\'accueil : appuie sur Partager puis « Sur l\'écran d\'accueil ».'
                : 'Installe Ma Cave sur ton téléphone pour un accès plus rapide.';
            document.body.appendChild(banner);

            const actionBtn = banner.querySelector('.install-banner-action');
            if (isIos) {
                actionBtn.remove(); // pas d'action programmatique possible sur iOS
            } else {
                actionBtn.addEventListener('click', onInstallClick);
            }
            banner.querySelector('.install-banner-close').addEventListener('click', function () {
                banner.remove();
                localStorage.setItem('installBannerDismissed', '1');
            });
        }

        function iconX() {
            return '<svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
        }

        if (isIos) {
            // Safari iOS ne déclenche jamais beforeinstallprompt : on affiche l'astuce directement.
            showBanner(null);
            return;
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            showBanner(function () {
                const banner = document.querySelector('.install-banner');
                deferredPrompt.prompt();
                deferredPrompt.userChoice.finally(function () {
                    deferredPrompt = null;
                    if (banner) banner.remove();
                });
            });
        });
    })();

    // --- Barre de progression des appels IA ---
    // Progression "asymptotique" : avance vite au début puis ralentit vers 90 %,
    // et se complète à l'arrivée de la réponse (la durée réelle n'est pas connue d'avance).
    function startAiProgress(statusEl) {
        const track = document.createElement('div');
        track.className = 'ai-progress';
        const bar = document.createElement('div');
        bar.className = 'ai-progress-bar';
        track.appendChild(bar);
        statusEl.parentNode.insertBefore(track, statusEl);
        let pct = 0;
        const timer = setInterval(function () {
            pct += (90 - pct) * 0.06;
            bar.style.width = pct + '%';
        }, 250);
        function stop(isError) {
            clearInterval(timer);
            if (isError) track.classList.add('error');
            bar.style.width = '100%';
            setTimeout(function () { track.remove(); }, isError ? 900 : 450);
        }
        return {
            finish: function () { stop(false); },
            fail: function () { stop(true); },
        };
    }
    window.aiProgress = startAiProgress;

    // --- Toasts ---
    function showToast(message, type) {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + (type || 'success');
        toast.textContent = message;
        container.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

    // --- Consommation rapide depuis les listes ---
    document.querySelectorAll('.btn-quick-consume').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const wineId = btn.getAttribute('data-wine-id');
            btn.disabled = true;
            try {
                const res = await fetch('/pages/quick_consume.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                    body: JSON.stringify({ wine_id: wineId }),
                });
                const json = await res.json();
                if (json.ok) {
                    showToast('Une bouteille consommée.', 'success');
                    document.querySelectorAll('[data-qty-for="' + wineId + '"]').forEach(function (cell) {
                        cell.textContent = json.remaining;
                    });
                } else {
                    showToast(json.error || 'Erreur lors de la consommation.', 'error');
                }
            } catch (e) {
                showToast('Erreur réseau.', 'error');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // --- Tri des colonnes ---
    document.querySelectorAll('table.sortable-table th[data-sort-key]').forEach(function (th) {
        th.addEventListener('click', function () {
            const table = th.closest('table');
            const index = Array.from(th.parentNode.children).indexOf(th);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const asc = !th.classList.contains('sorted-asc');

            table.querySelectorAll('th[data-sort-key]').forEach(function (t) {
                t.classList.remove('sorted-asc', 'sorted-desc');
            });
            th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');

            rows.sort(function (a, b) {
                const cellA = a.children[index];
                const cellB = b.children[index];
                const rawA = cellA.getAttribute('data-sort-value');
                const rawB = cellB.getAttribute('data-sort-value');
                let cmp;
                if (rawA !== null && rawB !== null) {
                    cmp = parseFloat(rawA) - parseFloat(rawB);
                } else {
                    cmp = cellA.textContent.trim().toLowerCase().localeCompare(cellB.textContent.trim().toLowerCase(), 'fr');
                }
                return asc ? cmp : -cmp;
            });
            rows.forEach(function (row) { tbody.appendChild(row); });
        });
    });

    // --- Onglets (tableau de bord) ---
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.getAttribute('data-tab');
            const tabsGroup = btn.closest('.tabs');
            if (tabsGroup) {
                tabsGroup.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
            }
            btn.classList.add('active');
            document.querySelectorAll('.tab-panel').forEach(function (panel) {
                panel.classList.toggle('active', panel.id === target);
            });
        });
    });

    // --- Recherche rapide globale ---
    const searchInput = document.getElementById('global-search-input');
    const searchResults = document.getElementById('global-search-results');
    if (searchInput && searchResults) {
        let searchTimer = null;
        let currentController = null;

        function closeSearch() {
            searchResults.classList.remove('open');
            searchResults.innerHTML = '';
        }

        function renderResults(results) {
            searchResults.innerHTML = '';
            if (!results.length) {
                const empty = document.createElement('div');
                empty.className = 'global-search-empty';
                empty.textContent = 'Aucun vin trouvé.';
                searchResults.appendChild(empty);
                searchResults.classList.add('open');
                return;
            }
            results.forEach(function (w) {
                const link = document.createElement('a');
                link.className = 'global-search-result';
                link.href = '/pages/wine_detail.php?id=' + w.id;

                const info = document.createElement('div');
                const name = document.createElement('div');
                name.className = 'name';
                name.textContent = w.name + (w.vintage ? ' ' + w.vintage : '');
                const meta = document.createElement('div');
                meta.className = 'meta';
                const metaParts = [];
                if (w.producer) metaParts.push(w.producer);
                metaParts.push(w.qty + ' bout.');
                if (w.locations) metaParts.push(w.locations);
                meta.textContent = metaParts.join(' · ');
                info.appendChild(name);
                info.appendChild(meta);
                link.appendChild(info);
                searchResults.appendChild(link);
            });
            searchResults.classList.add('open');
        }

        searchInput.addEventListener('input', function () {
            const q = searchInput.value.trim();
            clearTimeout(searchTimer);
            if (q.length < 2) {
                closeSearch();
                return;
            }
            searchTimer = setTimeout(async function () {
                if (currentController) currentController.abort();
                currentController = new AbortController();
                try {
                    const res = await fetch('/pages/quick_search.php?q=' + encodeURIComponent(q), { signal: currentController.signal });
                    const json = await res.json();
                    if (json.ok) renderResults(json.results);
                } catch (e) {
                    // requête annulée ou erreur réseau : on ignore
                }
            }, 250);
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeSearch();
                searchInput.blur();
            } else if (e.key === 'Enter') {
                const first = searchResults.querySelector('.global-search-result');
                if (first) window.location.href = first.href;
            }
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.header-search')) closeSearch();
        });
    }

    // --- Actions au balayage (swipe) sur les listes mobiles ---
    if (window.matchMedia('(max-width: 640px)').matches) {
        document.querySelectorAll('table.responsive-table tbody tr').forEach(function (tr) {
            const consumeBtn = tr.querySelector('.btn-quick-consume');
            const viewLink = tr.querySelector('a[href*="wine_detail.php"]');
            if (!consumeBtn && !viewLink) return;

            let startX = 0;
            let startY = 0;
            let currentX = 0;
            let dragging = false;
            let horizontal = false;

            tr.addEventListener('touchstart', function (e) {
                if (e.touches.length !== 1) return;
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                currentX = 0;
                dragging = true;
                horizontal = false;
                tr.style.transition = 'none';
            }, { passive: true });

            tr.addEventListener('touchmove', function (e) {
                if (!dragging) return;
                const dx = e.touches[0].clientX - startX;
                const dy = e.touches[0].clientY - startY;
                if (!horizontal) {
                    if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;
                    horizontal = Math.abs(dx) > Math.abs(dy);
                    if (!horizontal) { dragging = false; return; }
                }
                currentX = Math.max(-100, Math.min(100, dx));
                tr.style.transform = 'translateX(' + currentX + 'px)';
                tr.classList.toggle('swipe-consume', currentX <= -50 && !!consumeBtn);
                tr.classList.toggle('swipe-view', currentX >= 50 && !!viewLink);
            }, { passive: true });

            function endSwipe() {
                if (!dragging) return;
                dragging = false;
                tr.style.transition = 'transform 0.25s ease';
                tr.style.transform = 'translateX(0)';
                const triggerConsume = currentX <= -50 && consumeBtn;
                const triggerView = currentX >= 50 && viewLink;
                tr.classList.remove('swipe-consume', 'swipe-view');
                currentX = 0;
                if (triggerConsume) {
                    consumeBtn.click();
                } else if (triggerView) {
                    window.location.href = viewLink.href;
                }
            }

            tr.addEventListener('touchend', endSwipe);
            tr.addEventListener('touchcancel', endSwipe);
        });
    }

    // --- Agrandissement des photos d'étiquette (lightbox), sur toutes les pages ---
    const lightboxModal = document.getElementById('lightbox-modal');
    const lightboxImg = document.getElementById('lightbox-img');
    const lightboxCloseBtn = document.getElementById('lightbox-close-btn');
    if (lightboxModal && lightboxImg) {
        document.addEventListener('click', function (e) {
            const img = e.target.closest('.thumb, .label-photo, .label-photo-sm, .label-candidate img');
            if (!img || img.tagName !== 'IMG') return;
            e.preventDefault();
            e.stopPropagation();
            // data-full : version pleine résolution quand la vue est une vignette
            lightboxImg.src = img.dataset.full || img.src;
            lightboxModal.classList.add('open');
        });
        if (lightboxCloseBtn) {
            lightboxCloseBtn.addEventListener('click', function () {
                lightboxModal.classList.remove('open');
            });
        }
    }

    // --- Scan de code-barres EAN (Open Food Facts) ---
    const btnScanBarcode = document.getElementById('btn-scan-barcode');
    const barcodeModal = document.getElementById('barcode-modal');
    if (btnScanBarcode && barcodeModal) {
        const video = document.getElementById('barcode-video');
        const barcodeStatus = document.getElementById('barcode-status');
        let stream = null;
        let scanning = false;
        let detector = null;

        function stopCamera() {
            scanning = false;
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                stream = null;
            }
            video.srcObject = null;
        }

        function closeBarcodeModal() {
            stopCamera();
            barcodeModal.classList.remove('open');
        }

        async function lookupEan(ean) {
            barcodeStatus.textContent = 'Recherche du code ' + ean + '...';
            try {
                const res = await fetch('/pages/barcode_lookup.php?ean=' + encodeURIComponent(ean));
                const json = await res.json();
                if (!json.ok) {
                    barcodeStatus.textContent = json.error || 'Produit introuvable.';
                    if (stream) { scanning = true; scanLoop(); } // on laisse retenter un scan
                    return;
                }
                const d = json.data;
                const bcField = document.getElementById('barcode');
                if (bcField) bcField.value = ean; // conservé en base : sert à retrouver un prix
                if (d.name) document.getElementById('name').value = d.name;
                markSuggested('producer', d.producer);
                markSuggested('volume_ml', d.volume_ml);
                markSuggested('country', d.country);
                if (d.color) {
                    const colorEl = document.getElementById('color');
                    if (colorEl) { colorEl.value = d.color; colorEl.classList.add('field-suggested'); }
                }
                closeBarcodeModal();
                showToast('Produit trouvé : ' + (d.name || ean), 'success');
                // Compléter le reste de la fiche (région, cépages, fenêtre...) via l'IA
                const aiBtn = document.getElementById('btn-ai-text');
                if (aiBtn && d.name) aiBtn.click();
            } catch (err) {
                barcodeStatus.textContent = 'Erreur réseau lors de la recherche.';
                if (stream) { scanning = true; scanLoop(); }
            }
        }

        // Un QR de bouteille contient rarement l'EAN : le plus souvent une URL
        // (étiquette électronique UE, site du producteur) ou un lien GS1.
        function handleScannedCode(raw) {
            raw = raw.trim();
            if (/^(\d{8}|\d{13})$/.test(raw)) {
                lookupEan(raw);
                return;
            }
            const gs1 = raw.match(/\/01\/(\d{13,14})(\/|\?|$)/);
            if (gs1) {
                let gtin = gs1[1];
                if (gtin.length === 14) gtin = gtin.slice(1);
                lookupEan(gtin);
                return;
            }
            if (/^https?:\/\//i.test(raw)) {
                resolveQrUrl(raw);
                return;
            }
            barcodeStatus.textContent = 'Code lu mais contenu non reconnu : ' + raw.slice(0, 60);
            if (stream) { scanning = true; scanLoop(); }
        }

        async function resolveQrUrl(url) {
            barcodeStatus.textContent = 'QR code lu — analyse de la page liée...';
            try {
                const res = await fetch('/pages/qr_resolve.php?url=' + encodeURIComponent(url));
                const json = await res.json();
                if (!json.ok) {
                    barcodeStatus.innerHTML = 'QR code lu, mais impossible d\'en extraire le vin. ';
                    const a = document.createElement('a');
                    a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                    a.textContent = 'Ouvrir le lien';
                    barcodeStatus.appendChild(a);
                    if (stream) { scanning = true; scanLoop(); }
                    return;
                }
                document.getElementById('name').value = json.data.title;
                closeBarcodeModal();
                showToast('Nom récupéré depuis le QR code — vérifie-le puis lance l\'enrichissement IA.', 'success');
            } catch (err) {
                barcodeStatus.textContent = 'Erreur réseau lors de l\'analyse du QR code.';
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
            } catch (e) { /* frame illisible : on réessaie */ }
            setTimeout(scanLoop, 300);
        }

        btnScanBarcode.addEventListener('click', async function () {
            barcodeModal.classList.add('open');
            barcodeStatus.textContent = '';
            if (!('BarcodeDetector' in window)) {
                barcodeStatus.textContent = 'Scanner caméra non supporté par ce navigateur — saisis le code manuellement.';
                return;
            }
            try {
                detector = detector || new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'qr_code'] });
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                video.srcObject = stream;
                barcodeStatus.textContent = 'Vise le code-barres...';
                scanning = true;
                scanLoop();
            } catch (err) {
                barcodeStatus.textContent = 'Accès caméra impossible — saisis le code manuellement.';
            }
        });

        document.getElementById('barcode-manual-btn').addEventListener('click', function () {
            const ean = document.getElementById('barcode-manual').value.replace(/\D/g, '');
            if (!/^(\d{8}|\d{13})$/.test(ean)) {
                barcodeStatus.textContent = 'Code EAN invalide (8 ou 13 chiffres).';
                return;
            }
            scanning = false;
            lookupEan(ean);
        });

        document.getElementById('barcode-cancel').addEventListener('click', closeBarcodeModal);
        barcodeModal.addEventListener('click', function (e) {
            if (e.target === barcodeModal) closeBarcodeModal();
        });
    }

    // --- Case "Cadeau" : affiche/masque le champ occasion ---
    const giftCheckbox = document.getElementById('is_gift');
    const giftNoteField = document.getElementById('gift_note_field');
    if (giftCheckbox && giftNoteField) {
        giftCheckbox.addEventListener('change', function () {
            giftNoteField.style.display = giftCheckbox.checked ? '' : 'none';
        });
    }

    // --- Menu hamburger (mobile) ---
    const navToggle = document.getElementById('nav-toggle');
    const siteHeader = document.querySelector('.site-header');
    if (navToggle && siteHeader) {
        navToggle.addEventListener('click', function () {
            const isOpen = siteHeader.classList.toggle('nav-open');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        siteHeader.querySelectorAll('.main-nav a, .header-actions a').forEach(function (link) {
            link.addEventListener('click', function () {
                siteHeader.classList.remove('nav-open');
                navToggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

    // --- Choix explicite appareil photo / galerie ---
    const labelPhotoInput = document.getElementById('label_photo');
    const btnTakePhoto = document.getElementById('btn-take-photo');
    const btnChooseGallery = document.getElementById('btn-choose-gallery');
    const photoFilename = document.getElementById('photo-filename');
    if (labelPhotoInput && btnTakePhoto && btnChooseGallery) {
        btnTakePhoto.addEventListener('click', function () {
            labelPhotoInput.setAttribute('capture', 'environment');
            labelPhotoInput.click();
        });
        btnChooseGallery.addEventListener('click', function () {
            labelPhotoInput.removeAttribute('capture');
            labelPhotoInput.click();
        });
    }
    // Ajout ET édition partagent le même champ #label_photo (jamais les deux à
    // la fois sur une page) : le recadrage s'applique dans les deux cas.
    if (labelPhotoInput) {
        attachCropOnSelect(labelPhotoInput, photoFilename);
    }

    function markSuggested(fieldId, value) {
        const el = document.getElementById(fieldId);
        if (!el || value === undefined || value === null || value === '') return;
        el.value = value;
        el.classList.add('field-suggested');
    }

    function applySuggestions(data) {
        markSuggested('producer', data.producer);
        markSuggested('region', data.region);
        markSuggested('appellation', data.appellation);
        markSuggested('classification', data.classification);
        markSuggested('country', data.country);
        if (data.color) {
            const colorEl = document.getElementById('color');
            if (colorEl) {
                colorEl.value = data.color;
                colorEl.classList.add('field-suggested');
            }
        }
        if (Array.isArray(data.grape_varieties) && data.grape_varieties.length) {
            markSuggested('grape_varieties', data.grape_varieties.join(', '));
        }
        markSuggested('alcohol_percent', data.alcohol_percent);
        markSuggested('drink_from_year', data.drink_from_year);
        markSuggested('drink_until_year', data.drink_until_year);
        markSuggested('description', data.description);
        markSuggested('food_pairing', data.food_pairing);
    }

    // --- AI enrich from name/producer/vintage ---
    const btnAiText = document.getElementById('btn-ai-text');
    if (btnAiText) {
        btnAiText.addEventListener('click', async function () {
            const name = document.getElementById('name').value.trim();
            const status = document.getElementById('ai-status');
            if (!name) {
                status.textContent = 'Renseigne au moins le nom du vin.';
                return;
            }
            btnAiText.disabled = true;
            status.textContent = 'Interrogation de l\'IA en cours...';
            const prog = startAiProgress(status);
            try {
                const res = await fetch('/pages/ai_enrich.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                    body: JSON.stringify({
                        name: name,
                        producer: document.getElementById('producer').value.trim(),
                        vintage: document.getElementById('vintage').value.trim(),
                    }),
                });
                const json = await res.json();
                if (json.ok) {
                    applySuggestions(json.data);
                    status.textContent = 'Champs pré-remplis — vérifie et corrige si besoin.';
                    prog.finish();
                } else {
                    status.textContent = 'Erreur IA: ' + (json.error || 'inconnue');
                    prog.fail();
                }
            } catch (e) {
                status.textContent = 'Erreur réseau lors de l\'appel IA.';
                prog.fail();
            } finally {
                btnAiText.disabled = false;
            }
        });
    }

    // --- AI enrich from label photo ---
    const btnAiPhoto = document.getElementById('btn-ai-photo');
    if (btnAiPhoto) {
        btnAiPhoto.addEventListener('click', async function () {
            const fileInput = document.getElementById('label_photo');
            const status = document.getElementById('ai-status');
            if (!fileInput.files || !fileInput.files[0]) {
                status.textContent = 'Choisis d\'abord une photo d\'étiquette.';
                return;
            }
            btnAiPhoto.disabled = true;
            status.textContent = 'Analyse de la photo en cours...';
            const prog = startAiProgress(status);
            try {
                const photo = await window.downscaleImage(fileInput.files[0], 1600, 0.82);
                const formData = new FormData();
                formData.append('photo', photo, 'photo.jpg');
                const res = await fetch('/pages/ai_enrich_photo.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken() },
                    body: formData,
                });
                const json = await res.json();
                if (json.ok) {
                    if (json.data.producer && !document.getElementById('name').value) {
                        document.getElementById('name').value = json.data.producer;
                    }
                    applySuggestions(json.data);
                    status.textContent = 'Champs pré-remplis à partir de la photo — vérifie et corrige si besoin.';
                    prog.finish();
                } else {
                    status.textContent = 'Erreur IA: ' + (json.error || 'inconnue');
                    prog.fail();
                }
            } catch (e) {
                status.textContent = 'Erreur réseau lors de l\'analyse de la photo.';
                prog.fail();
            } finally {
                btnAiPhoto.disabled = false;
            }
        });
    }

    // --- Prix relevés Open Food Facts (fiche vin) ---
    const btnOpenPrices = document.getElementById('btn-open-prices');
    if (btnOpenPrices) {
        btnOpenPrices.addEventListener('click', async function () {
            const status = document.getElementById('open-prices-status');
            const results = document.getElementById('open-prices-results');
            const wineId = btnOpenPrices.getAttribute('data-wine-id');
            btnOpenPrices.disabled = true;
            results.innerHTML = '';
            status.textContent = 'Recherche sur Open Food Facts…';
            try {
                const res = await fetch('/pages/open_prices.php?id=' + encodeURIComponent(wineId));
                const json = await res.json();
                if (!json.ok) {
                    status.textContent = json.error || 'Aucun prix trouvé.';
                    return;
                }
                if (!json.prices || !json.prices.length) {
                    status.textContent = 'Aucun prix relevé pour ce code-barres sur Open Food Facts.';
                    return;
                }
                status.textContent = json.prices.length + ' relevé(s) trouvé(s) :';
                json.prices.forEach(function (p) {
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex; align-items:center; gap:0.7rem; padding:0.4rem 0; border-bottom:1px solid var(--border); font-size:0.9rem;';
                    const label = document.createElement('span');
                    label.style.flex = '1';
                    const bits = [p.price.toFixed(2) + ' ' + (p.currency || 'EUR')];
                    if (p.shop) bits.push(p.shop);
                    if (p.date) bits.push(p.date);
                    if (p.discounted) bits.push('(promo)');
                    label.textContent = bits.join(' — ');
                    const use = document.createElement('button');
                    use.type = 'button';
                    use.className = 'btn btn-sm';
                    use.textContent = 'Utiliser';
                    use.addEventListener('click', function () {
                        document.getElementById('accept-open-price').value = p.price;
                        const note = ['Open Food Facts', p.shop, p.date].filter(Boolean).join(' · ');
                        document.getElementById('accept-open-note').value = note;
                        document.getElementById('accept-open-price-form').submit();
                    });
                    row.appendChild(label);
                    row.appendChild(use);
                    results.appendChild(row);
                });
            } catch (e) {
                status.textContent = 'Erreur réseau lors de la recherche.';
            } finally {
                btnOpenPrices.disabled = false;
            }
        });
    }

    // --- Consume modal ---
    const consumeModal = document.getElementById('consume-modal');
    if (consumeModal) {
        document.querySelectorAll('.btn-consume').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('consume-stock-id').value = btn.getAttribute('data-stock-id');
                document.getElementById('consume-location').textContent = btn.getAttribute('data-location');
                const qtyInput = document.getElementById('consume-quantity');
                qtyInput.max = btn.getAttribute('data-max');
                qtyInput.value = 1;
                consumeModal.classList.add('open');
            });
        });
        const cancelBtn = document.getElementById('consume-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                consumeModal.classList.remove('open');
            });
        }
        consumeModal.addEventListener('click', function (e) {
            if (e.target === consumeModal) consumeModal.classList.remove('open');
        });
    }

    // --- Modifier l'emplacement d'une ligne de stock ---
    const moveStockModal = document.getElementById('move-stock-modal');
    if (moveStockModal) {
        document.querySelectorAll('.btn-move-stock').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('move-stock-id').value = btn.getAttribute('data-stock-id');
                const select = document.getElementById('move_stock_location');
                if (select) select.value = btn.getAttribute('data-location-id') || '';

                // Quantité : on propose tout par défaut, borné au stock de la ligne
                const max = parseInt(btn.getAttribute('data-max') || '1', 10);
                const qty = document.getElementById('move-stock-quantity');
                if (qty) {
                    qty.max = max;
                    qty.value = max;
                }
                const maxLabel = document.getElementById('move-stock-max');
                if (maxLabel) maxLabel.textContent = max;
                const fromLabel = document.getElementById('move-stock-from');
                if (fromLabel) fromLabel.textContent = btn.getAttribute('data-location') || '—';

                moveStockModal.classList.add('open');
            });
        });
        const cancelBtn = document.getElementById('move-stock-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                moveStockModal.classList.remove('open');
            });
        }
    }

    // --- Sélecteur visuel de casier (popup) ---
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const modal = document.getElementById(btn.getAttribute('data-open-modal'));
            if (modal) modal.classList.add('open');
        });
    });
    document.querySelectorAll('.rack-grid[data-target-select]').forEach(function (grid) {
        const targetId = grid.getAttribute('data-target-select');
        const select = document.getElementById(targetId);
        grid.querySelectorAll('.rack-picker-cell').forEach(function (cell) {
            cell.addEventListener('click', function () {
                if (select) {
                    // On lève d'abord le filtre par cave, sinon l'option choisie
                    // peut ne pas figurer dans la liste actuellement filtrée.
                    if (typeof select.showAllCellars === 'function') {
                        select.showAllCellars();
                    }
                    select.value = cell.getAttribute('data-location-id');
                    select.dispatchEvent(new Event('change'));
                }
                const modal = cell.closest('.modal-backdrop');
                if (modal) modal.classList.remove('open');
            });
        });
    });
    document.querySelectorAll('.rack-picker-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const modal = btn.closest('.modal-backdrop');
            if (modal) modal.classList.remove('open');
        });
    });
    document.querySelectorAll('.modal-backdrop').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.classList.remove('open');
        });
    });
})();
