</main>
<div class="modal-backdrop" id="lightbox-modal">
    <div class="lightbox-content">
        <button type="button" class="lightbox-close" id="lightbox-close-btn" aria-label="Fermer">&times;</button>
        <img id="lightbox-img" src="" alt="Étiquette agrandie">
    </div>
</div>
<script src="/assets/app.js?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: time() ?>"></script>
</body>
</html>
