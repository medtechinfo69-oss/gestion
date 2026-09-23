    <?php if (empty($footerInContent)): ?>
    </div>
    <?php endif; ?>
    <footer style="padding:18px 28px;color:var(--color-ink-faint);font-size:0.78rem;">
      <?= e(APP_NAME) ?> &middot; Assurialis © 2026
    </footer>
    <?php if (!empty($footerInContent)): ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('form.email-export-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Envoi en cours...';
      }
      return true;
    });
  });
});
</script>
<?php
// Widget de chat flottant (admin <-> superviseur)
require_once __DIR__ . '/chat_widget.php';
?>
<?php /* defer : le HTML n'est plus bloqué pendant le téléchargement et
         l'exécution des scripts ; l'ordre d'exécution reste garanti. */ ?>
<script defer src="<?= e(APP_URL) ?>/assets/js/multiselect.js?v=<?= e(asset_version('js/multiselect.js')) ?>"></script>
<script defer src="<?= e(APP_URL) ?>/assets/js/app.js?v=<?= e(asset_version('js/app.js')) ?>"></script>
</body>
</html>
