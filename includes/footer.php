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
<script src="<?= e(APP_URL) ?>/assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
