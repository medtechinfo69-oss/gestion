<?php
// Outil de diagnostic temporaire — À SUPPRIMER après utilisation.
// Usage : _measure.php?page=dashboard.php
require __DIR__ . '/includes/init.php';
$_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'nom_complet' => 'Test Admin', 'must_change_password' => false];

$allowed = ['dashboard.php', 'dossiers.php', 'profile.php', 'rh_dashboard.php', 'rh_employees.php',
            'rh_salaries.php', 'rh_history.php', 'rh_reports.php', 'vendeurs.php', 'superviseurs.php',
            'dossier_form.php', 'corbeille.php', 'origines.php', 'annulation.php'];
$page = basename($_GET['page'] ?? 'dashboard.php');
if (!in_array($page, $allowed, true)) { $page = 'dashboard.php'; }
require __DIR__ . '/' . $page;
?>
<script>
(function () {
  function m() {
    var lines = [];
    lines.push('MEASURE2 page=<?php echo e($page); ?> docScrollH=' + document.documentElement.scrollHeight + ' innerH=' + window.innerHeight);
    var content = document.querySelector('.content');
    if (content) {
      var cr = content.getBoundingClientRect();
      lines.push('CONTENT top=' + Math.round(cr.top + window.scrollY) + ' h=' + Math.round(cr.height) + ' bottomPage=' + Math.round(cr.bottom + window.scrollY));
      var lastBottom = cr.top + window.scrollY;
      Array.prototype.forEach.call(content.children, function (c) {
        var r = c.getBoundingClientRect();
        lastBottom = Math.max(lastBottom, r.bottom + window.scrollY);
        if (c.offsetHeight > 0) lines.push('  enfant <' + c.tagName.toLowerCase() + ' class="' + (c.className || '') + '"> top=' + Math.round(r.top + window.scrollY) + ' h=' + Math.round(r.height) + ' bottom=' + Math.round(r.bottom + window.scrollY));
      });
      lines.push('DERNIER element bas=' + Math.round(lastBottom) + ' | ESPACE APRES=' + Math.round(document.documentElement.scrollHeight - lastBottom));
    }
    var n = document.querySelector('.nav-group');
    if (n) lines.push('NAV clientH=' + n.clientHeight + ' scrollH=' + n.scrollHeight + ' (defile si scrollH>clientH)');
    var d = document.createElement('pre');
    d.id = 'MEASURE2OUT';
    d.textContent = lines.join('\n');
    document.body.appendChild(d);
  }
  if (document.readyState === 'complete') { m(); } else { window.addEventListener('load', m); }
})();
</script>