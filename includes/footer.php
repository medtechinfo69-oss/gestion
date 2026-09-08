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
<script src="<?= e(APP_URL) ?>/assets/js/app.js?v=<?= defined('CACHE_VERSION') ? CACHE_VERSION : (int) filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
