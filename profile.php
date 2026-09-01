<?php
require_once __DIR__ . '/includes/init.php';
require_login();

$user = current_user();
$force = isset($_GET['force']) && !empty($user['must_change_password']);

$pageTitle = 'Mon profil';
$pageSubtitle = $force ? 'Vous devez définir un nouveau mot de passe pour continuer' : '';
$activePage = 'profile';
require __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:560px;">
  <div class="card-header"><h2>Informations du compte</h2></div>
  <div class="card-body">
    <div class="detail-grid mb-16">
      <div class="detail-item"><div class="k">Nom</div><div class="v"><?= e($user['nom_complet']) ?></div></div>
      <div class="detail-item"><div class="k">Identifiant</div><div class="v"><?= e($user['username']) ?></div></div>
      <div class="detail-item"><div class="k">Rôle</div><div class="v"><?= $user['role'] === 'admin' ? 'Administrateur' : ($user['role'] === 'superviseur' ? 'Superviseur' : 'Vendeur') ?></div></div>
    </div>

    <?php if ($force): ?>
      <div class="alert alert-info">Pour votre sécurité, veuillez choisir un nouveau mot de passe avant de continuer.</div>
    <?php endif; ?>

    <h3>Changer le mot de passe</h3>
    <form action="<?= e(APP_URL) ?>/actions/profile_update.php" method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-group mb-16">
        <label for="current_password">Mot de passe actuel <span class="req">*</span></label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
      </div>
      <div class="form-group mb-16">
        <label for="new_password">Nouveau mot de passe <span class="req">*</span></label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="10">
        <div class="help-text">10 caractères minimum, avec au moins une majuscule, une minuscule et un chiffre.</div>
      </div>
      <div class="form-group mb-16">
        <label for="confirm_password">Confirmer le nouveau mot de passe <span class="req">*</span></label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="10">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Mettre à jour le mot de passe</button>
      </div>
    </form>

  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
