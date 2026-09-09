<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/security_integration.php';

$token = $_GET['token'] ?? '';
$error = '';
$downloadInfo = null;
$remainingAttempts = 0;
$locked = false;

sec_log('view', 'attachment', $token, 'Access secure download page');

if ($token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $error = 'Token invalide.';
    } else {
        $stmt = $db->prepare('SELECT * FROM secure_downloads WHERE token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $downloadInfo = $stmt->fetch();
        
        if (!$downloadInfo) {
            $error = 'Ce lien a expiré ou est invalide.';
        } elseif ($downloadInfo['expires_at'] <= date('Y-m-d H:i:s')) {
            $error = 'Ce lien a expiré.';
        } elseif ($downloadInfo['used_at'] !== null) {
            $error = 'Ce lien a déjà été utilisé.';
        } elseif ($downloadInfo['attempts'] >= $downloadInfo['max_attempts']) {
            $error = 'Trop de tentatives incorrectes. Le lien est bloqué.';
            $locked = true;
        } else {
            $remainingAttempts = $downloadInfo['max_attempts'] - $downloadInfo['attempts'];
        }
    }
} else {
    $error = 'Token manquant.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $downloadInfo && !$locked) {
    $enteredCode = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
    
    if (strlen($enteredCode) !== 6) {
        $error = 'Le code doit contenir 6 chiffres.';
        $remainingAttempts = $downloadInfo['max_attempts'] - $downloadInfo['attempts'];
    } else {
        $codeHash = hash('sha256', $enteredCode);
        
        if ($codeHash === $downloadInfo['code_hash']) {
            $clientIp = get_client_ip();
            
            $stmt = $db->prepare('UPDATE secure_downloads SET used_at = NOW(), used_ip = :ip WHERE token = :token');
            $stmt->execute(['ip' => $clientIp, 'token' => $token]);
            
            sec_log('download', 'attachment', $token, 'Secure download successful: ' . $downloadInfo['filename'], true);
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $downloadInfo['filename'] . '"');
            header('Content-Length: ' . strlen($downloadInfo['file_data']));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            echo $downloadInfo['file_data'];
            
            $deleteStmt = $db->prepare('DELETE FROM secure_downloads WHERE token = :token');
            $deleteStmt->execute(['token' => $token]);
            exit;
        } else {
            $stmt = $db->prepare('UPDATE secure_downloads SET attempts = attempts + 1, last_attempt_at = NOW() WHERE token = :token');
            $stmt->execute(['token' => $token]);
            
            $newAttempts = $downloadInfo['attempts'] + 1;
            sec_log('permission_denied', 'attachment', $token, 'Invalid download code attempt', false, 'Wrong code');
            
            if ($newAttempts >= $downloadInfo['max_attempts']) {
                $error = 'Code incorrect. Trop de tentatives - le lien est maintenant bloquÃ©.';
                $locked = true;
            } else {
                $remainingAttempts = $downloadInfo['max_attempts'] - $newAttempts;
                $error = "Code incorrect. Il vous reste $remainingAttempts tentative(s).";
            }
        }
    }
}

$pageTitle = 'Téléchargement sécurisé';
require __DIR__ . '/includes/header.php';
?>

<div class="content-card" style="max-width:500px;margin:40px auto;">
  <div class="card-head">
    <h2>Téléchargement sécurisé</h2>
  </div>
  <div style="padding:20px;text-align:center;">
    <?php if ($error): ?>
      <div style="color:#dc3545;margin-bottom:20px;padding:15px;background:#f8d7da;border-radius:5px;border:1px solid #f5c6cb;">
        <strong>Erreur</strong><br>
        <?= e($error) ?>
      </div>
      <?php if (!$locked): ?>
        <p style="margin-bottom:20px;">Entrez le code de vérification reçu par email.</p>
        <form method="post" style="max-width:300px;margin:0 auto;">
          <div style="margin-bottom:15px;">
            <input type="text" name="code" class="form-control" placeholder="Code à 6 chiffres" maxlength="6" required autofocus style="font-size:24px;text-align:center;letter-spacing:8px;" autocomplete="off" inputmode="numeric">
          </div>
          <button type="submit" class="btn btn-primary btn-block">Télécharger</button>
        </form>
      <?php endif; ?>
      <a href="<?= e(APP_URL) ?>/dossiers.php" class="btn btn-secondary" style="margin-top:15px;">Retour aux dossiers</a>
    <?php elseif ($downloadInfo): ?>
      <div style="margin-bottom:20px;">
        <p style="font-size:16px;margin-bottom:10px;">Entrez le code de vérification reçu par email.</p>
        <p style="font-size:12px;color:#666;">Fichier: <strong><?= e($downloadInfo['filename']) ?></strong></p>
        <p style="font-size:12px;color:#666;">Tentatives restantes: <strong><?= (int) $remainingAttempts ?></strong></p>
      </div>
      <form method="post" style="max-width:300px;margin:0 auto;">
        <div style="margin-bottom:15px;">
          <input type="text" name="code" class="form-control" placeholder="Code à 6 chiffres" maxlength="6" required autofocus style="font-size:24px;text-align:center;letter-spacing:8px;" autocomplete="off" inputmode="numeric">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Télécharger</button>
      </form>
      <p style="margin-top:20px;font-size:12px;color:#666;">
        Lien expire le: <?= date('d/m/Y à H:i', strtotime($downloadInfo['expires_at'])) ?>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
