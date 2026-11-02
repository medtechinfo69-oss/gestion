<?php
/**
 * Widget de chat flottant (cercle en bas à droite, style Messenger).
 * Visible par les admins, superviseurs, vendeurs ET comptes RH connectés.
 * Paires autorisées (voir chat_pair_allowed() dans includes/chat.php) :
 *   - l'administrateur discute avec tout le monde ;
 *   - un VENDEUR discute avec un SUPERVISEUR (et inversement).
 * Un compte RH ne voit que les administrateurs ; un vendeur ne voit dans ses
 * contacts que les administrateurs et les superviseurs.
 * Affiche la liste des contacts autorisés avec voyant vert = en ligne.
 */
if (!defined('APP_INIT') || !can_chat_role()) {
  return;
}
$chatMe = current_user();
$chatCsrf = csrf_token();

// Contacts rendus CÔTÉ SERVEUR : la liste s'affiche immédiatement, même si
// l'appel AJAX est bloqué par la protection de l'hébergeur (InfinityFree).
$chatContactsJs = [];
try {
    $chatDb = isset($db) ? $db : null;
    if ($chatDb instanceof PDO) {
        foreach (chat_get_contacts($chatDb, (int) $chatMe['id'], (string) ($chatMe['role'] ?? '')) as $chatC) {
            $chatId = (int) $chatC['id'];
            $chatContactsJs[$chatId] = [
                'id'       => $chatId,
                'username' => $chatC['username'],
                'name'     => ($chatC['nom_complet'] ?: $chatC['username']),
                'role'     => $chatC['role'],
                'online'   => (bool) $chatC['is_online'],
                'unread'   => (int) $chatC['unread'],
            ];
        }
    }
} catch (Throwable $chatE) {
    $chatContactsJs = []; // échec silencieux : le rafraîchissement AJAX prendra le relais
}
?>
<style>
  #chatWidget {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9999;
    font-family: inherit;
  }

  #chatBtn {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    background: #1a73e8;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(0, 0, 0, .28);
    position: relative;
    transition: transform .15s, background .15s;
  }

  #chatBtn:hover {
    transform: scale(1.06);
    background: #155ec2;
  }

  #chatBtn svg {
    width: 26px;
    height: 26px;
    fill: none;
    stroke: #fff;
    stroke-width: 2;
  }

  #chatPresence {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2.5px solid #fff;
    background: #9aa0a6;
  }

  #chatPresence.online {
    background: #22c55e;
    box-shadow: 0 0 6px #22c55e;
  }

  #chatBadge {
    position: absolute;
    top: -4px;
    left: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 10px;
    background: #e53935;
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    display: none;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
  }

  #chatPanel {
    display: none;
    position: absolute;
    bottom: 66px;
    right: 0;
    width: 320px;
    max-width: calc(100vw - 40px);
    height: 440px;
    max-height: calc(100vh - 120px);
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, .3);
    overflow: hidden;
    flex-direction: column;
  }

  #chatPanel.open {
    display: flex;
  }

  .chat-head {
    background: #1a73e8;
    color: #fff;
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .chat-head strong {
    font-size: .95rem;
  }

  .chat-head button {
    background: none;
    border: none;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    line-height: 1;
  }

  .chat-list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
  }

  .chat-contact {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
  }

  .chat-contact:hover {
    background: #f5f8ff;
  }

  .chat-contact.active {
    background: #e8f0fe;
  }

  .chat-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e3eaf5;
    color: #1a4fa0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    position: relative;
    flex: none;
  }

  .chat-dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: #9aa0a6;
    border: 2px solid #fff;
  }

  .chat-dot.online {
    background: #22c55e;
    box-shadow: 0 0 5px #22c55e;
  }

  .chat-contact .cc-info {
    flex: 1;
    min-width: 0;
  }

  .chat-contact .cc-name {
    font-weight: 600;
    font-size: .9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .chat-contact .cc-role {
    font-size: .72rem;
    color: #777;
  }

  .chat-contact .cc-badge {
    background: #e53935;
    color: #fff;
    border-radius: 10px;
    min-width: 18px;
    height: 18px;
    font-size: .7rem;
    font-weight: 700;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    flex: none;
  }

  .chat-conv {
    flex: 1;
    display: none;
    flex-direction: column;
    height: 100%;
    min-height: 0;
  }

  .chat-conv.open {
    display: flex;
  }

  .chat-conv-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    border-bottom: 1px solid #eee;
    background: #fafafa;
  }

  .chat-conv-head .back {
    background: none;
    border: none;
    font-size: 17px;
    cursor: pointer;
    color: #1a73e8;
  }

  .chat-msgs {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 12px;
    background: #eceef2;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .chat-msg {
    max-width: 78%;
    padding: 8px 12px;
    border-radius: 16px;
    font-size: .87rem;
    word-wrap: break-word;
    white-space: pre-wrap;
  }

  .chat-msg.mine {
    align-self: flex-end;
    background: #1a73e8;
    color: #fff;
    border-bottom-right-radius: 4px;
  }

  .chat-msg .ticks {
    display: inline-flex;
    align-items: center;
    margin-left: 5px;
    vertical-align: -2px;
  }

  .chat-msg .ticks svg {
    width: 15px;
    height: 15px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
  }

  .chat-msg.mine .ticks {
    color: rgba(255, 255, 255, .75);
  }

  /* Double coche "vu" : vert clair lumineux (bien visible sur le bleu). */
  .chat-msg.mine.seen .ticks {
    color: #4ade80;
    filter: drop-shadow(0 0 2px rgba(74, 222, 128, .55));
  }

  /* Messages REÇUS (visibles côté superviseur comme côté admin) :
     fond blanc franc + bordure + ombre légère pour bien se détacher
     du fond gris de la zone de conversation. */
  .chat-msg.theirs {
    align-self: flex-start;
    background: #ffffff;
    color: #1c1e21;
    border: 1px solid #d4d7dd;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .10);
    border-bottom-left-radius: 4px;
    font-weight: 500;
  }

  /* Le pseudo-auteur affiché au-dessus du message reçu (repère visuel). */
  .chat-msg .who {
    display: block;
    font-size: .68rem;
    font-weight: 700;
    color: #1a73e8;
    margin-bottom: 2px;
  }

  .chat-msg .t {
    display: block;
    font-size: .66rem;
    opacity: .7;
    margin-top: 3px;
  }

  .chat-empty {
    color: #999;
    text-align: center;
    font-size: .82rem;
    margin: auto;
    padding: 20px;
  }

  .chat-form {
    display: flex;
    gap: 8px;
    padding: 10px;
    border-top: 1px solid #eee;
    background: #fff;
    flex: none;
  }

  .chat-form input {
    flex: 1;
    border: 1px solid #dfe1e5;
    border-radius: 18px;
    padding: 8px 14px;
    font-size: .87rem;
    outline: none;
  }

  .chat-form input:focus {
    border-color: #1a73e8;
  }

  .chat-form button {
    border: none;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    background: #1a73e8;
    color: #fff;
    cursor: pointer;
    font-size: 15px;
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .chat-form button:disabled {
    opacity: .5;
    cursor: default;
  }

  .chat-msg.pending.failed {
    opacity: .6;
    border: 1px solid #e53935;
  }

  /* ---- Bouton son / muet ---- */
  #chatMute {
    background: none;
    border: none;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    line-height: 1;
    padding: 2px;
    opacity: .9;
  }

  #chatMute:hover { opacity: 1; }
  #chatHead { display: flex; align-items: center; }
  .chat-head > strong { flex: 1; }

  /* ---- Badge animé quand de nouveaux messages arrivent ---- */
  @keyframes chatBadgePop {
    0% { transform: scale(1); }
    40% { transform: scale(1.35); }
    100% { transform: scale(1); }
  }

  #chatBadge.chat-badge-pop { animation: chatBadgePop .35s ease; }

  /* Contacts avec messages non lus : fond teinté (mise en évidence). */
  .chat-contact.has-unread { background: #fff4f4; }
  .chat-contact.has-unread:hover { background: #ffeded; }
  .chat-contact.has-unread .cc-name { color: #b71c1c; }

  [data-theme='dark'] .chat-contact.has-unread { background: rgba(229, 57, 53, .12); }

  /* ---- Popup « nouveau message » (toast) ---- */
  @keyframes chatToastIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
  }

  @keyframes chatToastOut {
    from { opacity: 1; }
    to { opacity: 0; }
  }

  .chat-toast {
    position: fixed;
    right: 20px;
    bottom: 88px;
    z-index: 10000;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-left: 4px solid #e53935;
    border-radius: 10px;
    box-shadow: 0 6px 22px rgba(0, 0, 0, .22);
    padding: 10px 14px;
    max-width: 300px;
    cursor: pointer;
    font-family: inherit;
    animation: chatToastIn .25s ease;
  }

  .chat-toast.chat-toast-out { animation: chatToastOut .3s ease forwards; }

  .chat-toast .ct-title { font-weight: 700; font-size: .88rem; margin-bottom: 3px; }
  .chat-toast .ct-sender { font-size: .82rem; color: #1a73e8; font-weight: 600; }
  .chat-toast .ct-text { font-size: .8rem; color: #555; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap; }

  [data-theme='dark'] .chat-toast { background: #16243d; border-color: #223354; }
  [data-theme='dark'] .chat-toast .ct-text { color: #b9c4d6; }
</style>

<div id="chatWidget">
  <button id="chatBtn" type="button" aria-label="Chat interne">
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 9 9 0 0 1-3.8-.8L3 21l2-5.2a8 8 0 0 1-.9-3.8A8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z" />
    </svg>
    <span id="chatBadge">0</span>
    <span id="chatPresence" title="Statut"></span>
  </button>

  <div id="chatPanel" role="dialog" aria-label="Chat interne">
    <div class="chat-head">
      <strong>Messages</strong>
      <button type="button" id="chatMute" aria-label="Couper le son des notifications" title="Son des notifications">&#128266;</button>
      <button type="button" id="chatClose" aria-label="Fermer">&times;</button>
    </div>

    <div class="chat-list" id="chatList"></div>

    <div class="chat-conv" id="chatConv">
      <div class="chat-conv-head">
        <button type="button" class="back" id="chatBack" aria-label="Retour">&#8592;</button>
        <strong id="chatPeerName" style="font-size:.9rem;"></strong>
      </div>
      <div class="chat-msgs" id="chatMsgs"></div>
      <form class="chat-form" id="chatForm" autocomplete="off">
        <input type="text" id="chatInput" placeholder="Écrivez un message..." maxlength="5000" autocomplete="off">
        <button type="submit" id="chatSendBtn" aria-label="Envoyer">&#10148;</button>
      </form>
    </div>
  </div>
</div>

<script>
  (function () {
    var CSRF = <?= json_encode($chatCsrf) ?>;
    // Règle d'accès (source unique côté serveur : chat_pair_allowed() en PHP) :
    //   - l'administrateur discute avec tout le monde ;
    //   - un VENDEUR discute avec un SUPERVISEUR (et inversement) ;
    //   - un compte RH ne discute qu'avec l'administrateur.
    var IS_ADMIN = <?= is_admin() ? 'true' : 'false' ?>;
    var MY_ROLE = <?= json_encode((string) ($chatMe['role'] ?? '')) ?>;
    // Libellé d'un rôle pour l'affichage (miroir JS de role_label() en PHP).
    // Passé par PHP pour rester la source unique : un nouveau rôle n'a pas
    // à être ajouté deux fois.
    // NB : boucle simple volontairement (pas de fn() fléchée, qui exige
    // PHP 7.4) — plusieurs hébergeurs mutualisés tournent encore en 7.2/7.3
    // et une simple erreur de syntaxe rendrait TOUTES les pages en HTTP 500.
    <?php
    $roleLabelsJs = [];
    foreach (login_allowed_roles() as $roleName) {
        $roleLabelsJs[$roleName] = role_label($roleName);
    }
    ?>
    var ROLE_LABELS = <?= json_encode($roleLabelsJs) ?>;

    /** Libellé lisible d'un rôle, avec repli sur le rôle brut. */
    function roleText(role) {
      return ROLE_LABELS[role] || (role || '');
    }
    // TRANSPORT PRINCIPAL : la PAGE COURANTE sert l'API (?chat_api=...).
    // Certains hébergeurs gratuits (InfinityFree) filtrent les requêtes vers
    // le dossier actions/ ; une requête vers la page elle-même passe toujours.
    var PAGE = location.pathname + location.search;
    function apiGet(params) {
      var q = location.search ? location.search.replace(/^\?/, '') : '';
      return location.pathname + '?' + (q ? q + '&' : '') + params;
    }
    var btn = document.getElementById('chatBtn'),
      presence = document.getElementById('chatPresence'),
      badge = document.getElementById('chatBadge'),
      panel = document.getElementById('chatPanel'),
      listEl = document.getElementById('chatList'),
      convEl = document.getElementById('chatConv'),
      msgsEl = document.getElementById('chatMsgs'),
      peerNameEl = document.getElementById('chatPeerName'),
      input = document.getElementById('chatInput'),
      sendBtn = document.getElementById('chatSendBtn'),
      // Contacts pré-remplis côté serveur : visibles même sans AJAX.
      contacts = <?= json_encode((object) $chatContactsJs) ?>,
      peer = 0, lastId = 0, statusTimer = null, msgTimer = null, retryTimer = null,
      readUntil = 0, lastMineId = 0, lastDiag = null, apiFail = false;

    /* ============ SYNCHRO RAPIDE (style Messenger) ============
      Rythme adaptatif : 2 s en conversation ouverte, 5 s sur le reste
      quand l'onglet est ACTIF ; ralenti automatiquement (20 s) quand
      l'onglet est caché pour ne pas charger inutilement le serveur.
      Complété par : diffusion instantanée locale (BroadcastChannel),
      burst de 3 rafraîchissements après chaque envoi, et synchronisation
      immédiate sur retour sur l'onglet / focus / reprise réseau. */
    var FAST_CONV_MS = 2000,   // conversation ouverte, onglet actif
        FAST_IDLE_MS = 5000,   // pas de conversation ouverte, onglet actif
        SLOW_HIDDEN_MS = 20000, // onglet en arrière-plan
        burstLeft = 0;

    /** Intervalle courant selon l'état (conversation / visibilité). */
    function currentDelay() {
      if (document.hidden) return SLOW_HIDDEN_MS;
      return peer > 0 ? FAST_CONV_MS : FAST_IDLE_MS;
    }

    /** (Re)programme le chronomètre de statut avec le bon rythme. */
    function rescheduleStatus() {
      clearInterval(statusTimer);
      statusTimer = setInterval(refreshStatus, currentDelay());
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    /* ============ NOTIFICATIONS (LOCAL, sans service externe) ============
       Son : WebAudio (aucun fichier, aucune API payante). Muet persistant
       via localStorage. Titre onglet + badge animé + popup + Notification
       API. Anti-doublon : on ne signale que les AUGMENTATIONS de non-lus. */
    var baseTitle = document.title;
    var notifState = {}; // id contact -> unread déjà signalé
    var chatNotifAC = null;

    function muted() {
      try { return localStorage.getItem('chatMuted') === '1'; } catch (e) { return false; }
    }
    function setMuted(v) {
      try { localStorage.setItem('chatMuted', v ? '1' : '0'); } catch (e) {}
      var m = document.getElementById('chatMute');
      if (m) { m.innerHTML = v ? '&#128263;' : '&#128266;'; m.title = v ? 'Activer le son' : 'Couper le son'; }
    }
    var muteBtn = document.getElementById('chatMute');
    if (muteBtn) {
      setMuted(muted());
      muteBtn.addEventListener('click', function () { setMuted(!muted()); });
    }

    /** Bip de notification : deux notes courtes, générées localement. */
    function playSound() {
      if (muted()) return;
      try {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        chatNotifAC = chatNotifAC || new AC();
        if (chatNotifAC.state === 'suspended') chatNotifAC.resume();
        var t = chatNotifAC.currentTime;
        [[880, 0], [1174.7, .15]].forEach(function (n) {
          var o = chatNotifAC.createOscillator(), g = chatNotifAC.createGain();
          o.type = 'sine'; o.frequency.value = n[0];
          g.gain.setValueAtTime(0.0001, t + n[1]);
          g.gain.exponentialRampToValueAtTime(.18, t + n[1] + .02);
          g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + .18);
          o.connect(g); g.connect(chatNotifAC.destination);
          o.start(t + n[1]); o.stop(t + n[1] + .2);
        });
      } catch (e) { /* audio indisponible : silencieux */ }
    }

    /** Titre de l'onglet : (n) Nouveau(x) message(s) — titre d'origine. */
    function updateTitle(n) {
      document.title = n > 0 ? '(' + n + ') ' + (n > 1 ? 'Nouveaux messages' : 'Nouveau message') + ' — ' + baseTitle : baseTitle;
    }

    /** Total des non-lus (recalcul local après une lecture). */
    function totalUnread() {
      var n = 0; for (var k in contacts) { if (contacts[k].unread) n += contacts[k].unread; } return n;
    }

    /** Popup toast locale (anti-doublon géré par l'appelant). */
    function showToast(sender, preview, contactId) {
      var old = document.querySelector('.chat-toast');
      if (old) old.remove();
      var t = document.createElement('div');
      t.className = 'chat-toast';
      t.innerHTML = '<div class="ct-title">\uD83D\uDD14 Vous avez un nouveau message !</div>' +
        '<div class="ct-sender"></div>' + (preview ? '<div class="ct-text"></div>' : '');
      t.querySelector('.ct-sender').textContent = sender;
      if (preview) t.querySelector('.ct-text').textContent = preview;
      t.addEventListener('click', function () {
        t.remove();
        if (!panel.classList.contains('open')) btn.click();
        if (typeof openConv === 'function') openConv(contactId);
      });
      document.body.appendChild(t);
      setTimeout(function () {
        t.classList.add('chat-toast-out');
        setTimeout(function () { if (t.parentNode) t.remove(); }, 350);
      }, 6000);
    }

    /** Notification bureau native (si permission accordée). */
    function desktopNotify(sender, preview) {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      try {
        var n = new Notification('\uD83D\uDD14 Nouveau message — ' + sender,
          { body: preview || 'Ouvrez le chat pour lire le message.', tag: 'chat-new-msg' });
        n.onclick = function () { try { window.focus(); n.close(); } catch (e2) {} };
        setTimeout(function () { try { n.close(); } catch (e3) {} }, 8000);
      } catch (e) { /* sans service worker : on ignore */ }
    }

    /**
     * Compare les non-lus AVANT/APRÈS : ne sonne/toaste que pour une
     * AUGMENTATION (pas au 1er chargement, pas de doublon ensuite).
     */
    function notifyNewMessages(newContacts) {
      var total = 0, fresh = null;
      newContacts.forEach(function (c) {
        total += c.unread;
        var before = notifState[c.id] || 0;
        if (c.unread > before) { fresh = c; }
        notifState[c.id] = c.unread;
      });
      updateTitle(total);
      if (!fresh) return;
      playSound();
      var bdg = document.getElementById('chatBadge');
      if (bdg) {
        bdg.classList.remove('chat-badge-pop');
        void bdg.offsetWidth; // relance l'animation CSS
        bdg.classList.add('chat-badge-pop');
      }
      showToast(fresh.name, fresh.preview || '', fresh.id);
      desktopNotify(fresh.name, fresh.preview || '');
    }

    // Coche simple (envoyé) / double coche bleue (vu) — style Messenger.
    var TICK_ONE = '<svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>';
    var TICK_TWO = '<svg viewBox="0 0 26 24"><path d="M2 13l4 4L16 7"/><path d="M10 17l2 2L24 8"/></svg>';
    function ticksHtml(seen) { return '<span class="ticks" title="' + (seen ? 'Vu' : 'Envoyé') + '">' + (seen ? TICK_TWO : TICK_ONE) + '</span>'; }

    /** Nouvelle tentative après un échec (protection hébergeur / réseau). */
    function scheduleRetry() {
      if (retryTimer) return;
      retryTimer = setTimeout(function () { retryTimer = null; refreshStatus(); }, 15000);
    }

    function convOpen() { return peer > 0 && convEl.classList.contains('open'); }

    /**
     * GET tolérant au bouclier de l'hébergeur : fetch d'abord ; si la réponse
     * n'est pas du JSON (page de sécurité HTML renvoyée à la place), on
     * recharge l'URL dans un iframe caché — une navigation native passe le
     * bouclier, et le corps reste lisible car même origine.
     */
    function hybridGet(url, onData, onFail) {
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { onData(d); })
        .catch(function () {
          var frame = document.createElement('iframe');
          frame.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';
          var done = false;
          var finish = function (ok) {
            if (done) return;
            done = true;
            try { frame.remove(); } catch (e2) {}
            if (!ok && onFail) onFail();
          };
          frame.addEventListener('load', function () {
            try {
              var txt = frame.contentDocument && frame.contentDocument.body
                ? frame.contentDocument.body.textContent : '';
              var d = JSON.parse(txt);
              finish(true);
              onData(d);
            } catch (e3) { finish(false); }
          });
          frame.src = url;
          document.body.appendChild(frame);
          setTimeout(function () { finish(false); }, 8000);
        });
    }

    function refreshStatus() {
      hybridGet(apiGet('chat_api=status'), function (d) {
        apiFail = false;
        if (!d.ok) return;
        lastDiag = d.diag || null;
        var online = false, unread = 0;
        // Liste REMPLACÉE (et non fusionnée) : la liste renvoyée par le serveur
        // fait déjà foi (chaque rôle ne reçoit que ses correspondants autorisés :
        // admin = tout le monde, vendeur = admins + superviseurs, etc.).
        contacts = {};
        d.contacts.forEach(function (c) { contacts[c.id] = c; if (c.online) online = true; unread += c.unread; });
        presence.classList.toggle('online', online);
        if (unread > 0) { badge.style.display = 'flex'; badge.textContent = unread > 99 ? '99+' : unread; }
        else { badge.style.display = 'none'; }
        notifyNewMessages(d.contacts);
        if (!convOpen()) renderList();
        else updateUnreadInList();
        // Burst actif : enchaîne les rafraîchissements rapprochés
        // (juste après un envoi) tant que le compteur n'est pas épuisé.
        if (burstLeft > 0) {
          burstLeft--;
          setTimeout(refreshStatus, 700);
        }
      }, function () {
        apiFail = true; lastDiag = null;
        presence.classList.remove('online');
        // Le bouclier de l'hébergeur renvoie parfois une page HTML à la
        // place du JSON : un rechargement de la page le fait passer
        // (son script pose le cookie). UNE SEULE FOIS par session d'onglet.
        try {
          if (!sessionStorage.getItem('chatReloadDone')) {
            sessionStorage.setItem('chatReloadDone', '1');
            location.reload();
            return;
          }
        } catch (e) { /* sessionStorage indisponible */ }
        scheduleRetry();
      });
    }

    /** Message affiché quand la liste est vide : explique la VRAIE raison. */
    function adminEmptyListMessage() {
      if (apiFail) {
        return 'API du chat momentanément injoignable (protection de l’hébergeur). Reconnexion automatique en cours…';
      }
      var d = lastDiag;
      if (d && d.erreur_sql) return 'Erreur SQL : ' + d.erreur_sql;
      var rep = (d && d.repartition) || [], inactifs = 0, actifsAdminSup = 0;
      rep.forEach(function (r) {
        if (r.role === 'admin' || r.role === 'superviseur') {
          if (r.actif) { actifsAdminSup += r.n; }
          else if (r.role === 'superviseur') { inactifs += r.n; }
        }
      });
      if (inactifs > 0 && actifsAdminSup <= 1) {
        return inactifs + ' superviseur(s) existe(nt) mais sont désactivés — activez-les dans la page «&nbsp;Superviseurs&nbsp;».';
      }
      if (actifsAdminSup <= 1) {
        return 'Aucun autre compte actif. Créez un superviseur (page «&nbsp;Superviseurs&nbsp;») ou un vendeur (page «&nbsp;Vendeurs&nbsp;») pour pouvoir discuter.';
      }
      if (d) {
        return 'Contacts attendus mais introuvables. Copiez ce code pour l’assistance : ' + JSON.stringify(d);
      }
      return 'Aucun contact disponible. Activez ou créez un autre compte (admin, superviseur ou vendeur) pour discuter.';
    }

    /**
     * Message quand la liste est vide : chaque rôle voit la bonne explication.
     * Rappel des paires autorisées (miroir de chat_pair_allowed() en PHP) :
     *   admin -> tout le monde ; vendeur <-> superviseur ; rh -> admin seul.
     */
    function emptyListMessage() {
      if (!IS_ADMIN) {
        if (apiFail) {
          return 'API du chat momentanément injoignable (protection de l’hébergeur). Reconnexion automatique en cours…';
        }
        if (MY_ROLE === 'vendeur') {
          return 'Aucun contact disponible. Vous pouvez écrire à l’administrateur ou à un superviseur.';
        }
        if (MY_ROLE === 'superviseur') {
          return 'Aucun contact disponible. Vous pouvez écrire à l’administrateur ou à un vendeur.';
        }
        return 'Aucun administrateur disponible pour le chat. Vous pouvez écrire uniquement à l’administrateur.';
      }
      return adminEmptyListMessage();
    }

    function renderList() {
      var html = '';
      var arr = Object.values(contacts);
      arr.sort(function (a, b) { return (b.online - a.online) || (b.unread - a.unread) || a.name.localeCompare(b.name); });
      if (!arr.length) html = '<div class="chat-empty">' + esc(emptyListMessage()) + '</div>';
      arr.forEach(function (c) {
        html += '<div class="chat-contact' + (c.id === peer ? ' active' : '') + (c.unread ? ' has-unread' : '') + '" data-id="' + c.id + '">' +
          '<div class="chat-avatar">' + esc((c.name || '?').charAt(0).toUpperCase()) +
          '<span class="chat-dot' + (c.online ? ' online' : '') + '"></span></div>' +
          '<div class="cc-info"><div class="cc-name">' + esc(c.name) + '</div>' +
          '<div class="cc-role">' + roleText(c.role) +
          (c.online ? ' &middot; en ligne' : ' &middot; hors ligne') + '</div></div>' +
          '<span class="cc-badge"' + (c.unread ? ' style="display:flex"' : '') + '>' + c.unread + '</span></div>';
      });
      listEl.innerHTML = html;
      listEl.querySelectorAll('.chat-contact').forEach(function (el) {
        el.addEventListener('click', function () { openConv(parseInt(el.dataset.id, 10)); });
      });
    }

    function updateUnreadInList() {
      listEl.querySelectorAll('.chat-contact').forEach(function (el) {
        var c = contacts[parseInt(el.dataset.id, 10)];
        if (!c) return;
        el.classList.toggle('has-unread', !!c.unread);
        var b = el.querySelector('.cc-badge');
        if (c.unread) { b.style.display = 'flex'; b.textContent = c.unread; } else { b.style.display = 'none'; }
      });
    }

    function openConv(id) {
      peer = id; lastId = 0; readUntil = 0; lastMineId = 0; msgsEl.innerHTML = '';
      var c = contacts[id];
      peerNameEl.textContent = c ? c.name : 'Conversation';
      listEl.style.display = 'none';
      convEl.classList.add('open');
      renderList(); // reset active class + badges
      // Chargement hybride (fetch -> iframe) : fonctionne même si le bouclier
      // de l'hébergeur avale les requêtes AJAX.
      hybridGet(apiGet('chat_api=messages&peer=' + id + '&after=0'), function (d) {
        if (!d.ok) return;
        // D'abord connaître jusqu'où mes messages ont été lus par le
        // correspondant, sinon le 1er rendu afficherait 1 coche à tort.
        if (d.receipt) {
          readUntil = d.receipt.read_until || 0;
          lastMineId = Math.max(lastMineId, d.receipt.last_id || 0);
        }
        d.messages.forEach(appendMsg);
        markSeen();
        msgsEl.scrollTop = msgsEl.scrollHeight;
      });
      clearInterval(msgTimer);
      msgTimer = setInterval(pollMessages, 2000); // quasi temps réel (onglet actif)
      rescheduleStatus(); // conversation ouverte = statut aussi accéléré
      input.focus();
    }

    function applyReceipt(receipt) {
      if (!receipt) return;
      var before = readUntil;
      readUntil = receipt.read_until || 0;
      lastMineId = Math.max(lastMineId, receipt.last_id || 0);
      // Ne redessine que si l'état "vu" a progressé (évite le travail inutile).
      if (readUntil !== before) markSeen();
    }

    /**
     * Met à jour les coches de TOUS mes messages selon l'état de lecture :
     *   - envoyé, pas encore lu   -> 1 coche grise
     *   - lu par le correspondant -> 2 coches bleues ("Vu")
     * Fonctionne pour TOUS les rôles (logique symétrique : celui qui envoie
     * voit les coches, celui qui reçoit fait passer à "vu"), y compris la
     * conversation vendeur <-> superviseur.
     */
    function markSeen() {
      msgsEl.querySelectorAll('.chat-msg.mine[data-id]').forEach(function (el) {
        var id = parseInt(el.getAttribute('data-id'), 10);
        var seen = readUntil > 0 && id <= readUntil;
        el.classList.toggle('seen', seen);
        var t = el.querySelector('.ticks');
        if (t) {
          t.innerHTML = seen ? TICK_TWO : TICK_ONE;
          t.title = seen ? 'Vu' : 'Envoyé';
        }
      });
    }

    function appendRow(html) {
      var wrap = document.createElement('div');
      wrap.innerHTML = html;
      while (wrap.firstChild) msgsEl.appendChild(wrap.firstChild);
      msgsEl.scrollTop = msgsEl.scrollHeight;
    }

    // Nom du correspondant, pour repérer clairement les messages reçus.
    function peerLabel() {
      var c = contacts[peer];
      return c ? c.name : 'Correspondant';
    }

    function appendMsg(m) {
      // Remplace une éventuelle bulle optimiste déjà confirmée (même id)
      // par le rendu serveur réel (horodatage + état « vu »), sans doublon.
      if (m.mine && m.id) {
        var optimistic = msgsEl.querySelector('.chat-msg.mine[data-id="' + m.id + '"]');
        if (optimistic) optimistic.remove();
      }
      var seen = m.mine && readUntil > 0 && m.id <= readUntil;
      var who = m.mine ? '' : '<span class="who">' + esc(peerLabel()) + '</span>';
      var html = '<div class="chat-msg' + (m.mine ? ' mine' + (seen ? ' seen' : '') : ' theirs') +
        '" data-id="' + m.id + '">' + who + esc(m.text) +
        (m.mine ? ticksHtml(seen) : '') +
        '<span class="t">' + esc(m.time) + '</span></div>';
      appendRow(html);
      lastId = Math.max(lastId, m.id);
      // Le rendu suit la date du serveur : pas de doublon avec le message optimiste.
      if (m.mine && m.time) {
        var pending = msgsEl.querySelector('.chat-msg.mine.pending');
        if (pending) pending.remove();
      }
      if (m.mine) lastMineId = Math.max(lastMineId, m.id);
    }

    // Affiche immédiatement le message avec une seule coche (gris),
    // puis le poll le remplace par la version du serveur (id + date réelle).
    // Si le serveur n'a toujours pas confirmé après 12 s, la bulle passe en
    // « non envoyé — réessayez » au lieu de rester bloquée sur « envoi... ».
    function appendOptimistic(text, serverId) {
      appendRow('<div class="chat-msg mine pending" data-id="' + (serverId || '') + '">' + esc(text) +
        '<span class="ticks" title="Envoi...">' + TICK_ONE + '</span>' +
        '<span class="t">envoi...</span></div>');
      var el = msgsEl.querySelector('.chat-msg.mine.pending');
      if (serverId && el) {
        // Le serveur a confirmé l'enregistrement (send = ok + id) : la bulle
        // ne peut plus être « non envoyée ». Le sondage apportera ensuite
        // l'horodatage réel et remplacera cette bulle (même data-id).
        el.classList.remove('pending');
        var tick = el.querySelector('.ticks');
        if (tick) tick.title = 'Envoyé';
        var tEl = el.querySelector('.t');
        if (tEl) tEl.textContent = 'envoyé';
      }
      setTimeout(function () {
        if (el && el.parentNode && el.classList.contains('pending')) {
          el.classList.add('failed');
          var t = el.querySelector('.t');
          if (t) t.textContent = 'non envoyé — réessayez';
          var tk = el.querySelector('.ticks');
          if (tk) tk.title = 'Non envoyé';
        }
      }, 12000);
    }

    function pollMessages() {
      if (!peer) return;
      hybridGet(apiGet('chat_api=messages&peer=' + peer + '&after=' + lastId), function (d) {
        if (!d.ok) return;
        applyReceipt(d.receipt);
        if (d.messages.length) d.messages.forEach(appendMsg);
      });
    }

    function closeConv() {
      peer = 0; clearInterval(msgTimer);
      convEl.classList.remove('open');
      listEl.style.display = '';
      renderList();
      rescheduleStatus(); // retour au rythme "liste" (5 s)
    }

    document.getElementById('chatBack').addEventListener('click', closeConv);
    document.getElementById('chatClose').addEventListener('click', function () {
      panel.classList.remove('open');
      clearInterval(msgTimer); peer = 0;
    });

    btn.addEventListener('click', function () {
      var open = panel.classList.toggle('open');
      if (open) { refreshStatus(); if (!convOpen()) { renderList(); } else { closeConv(); } }
      else { clearInterval(msgTimer); peer = 0; }
    });

    /**
     * POST tolérant : si le bouclier de l'hébergeur avale la réponse AJAX,
     * on soumet un formulaire natif dans un iframe caché. La soumission
     * native passe le bouclier comme une navigation normale : le message
     * est enregistré côté serveur même si la réponse JSON est illisible.
     */
    function postViaForm(action, fields, onDone) {
      var frame = document.createElement('iframe');
      frame.name = 'chatPost' + Date.now();
      frame.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';
      document.body.appendChild(frame);
      var f = document.createElement('form');
      f.method = 'POST';
      f.action = PAGE; // la page courante sert l'API (?chat_api=... en champ caché)
      f.target = frame.name;
      f.style.display = 'none';
      var data = { chat_api: action, action: action, csrf_token: CSRF };
      for (var k in fields) { if (Object.prototype.hasOwnProperty.call(fields, k)) data[k] = fields[k]; }
      for (var k2 in data) {
        if (Object.prototype.hasOwnProperty.call(data, k2)) {
          var inp = document.createElement('input');
          inp.type = 'hidden'; inp.name = k2; inp.value = String(data[k2]);
          f.appendChild(inp);
        }
      }
      document.body.appendChild(f);
      var done = false;
      var cleanup = function () {
        try { f.remove(); } catch (e2) {}
        try { frame.remove(); } catch (e3) {}
      };
      frame.addEventListener('load', function () {
        if (done) return;
        done = true; onDone(); cleanup();
      });
      setTimeout(function () {
        if (!done) { done = true; onDone(); }
        cleanup();
      }, 4000);
      f.submit();
    }

    /**
     * Envoi d'un message : AJAX d'abord (rapide), formulaire natif en repli.
     */
    function hybridSend(p, text, onOk, onErr) {
      var body = new URLSearchParams({ chat_api: 'send', action: 'send', peer_id: p, message: text, csrf_token: CSRF });
      fetch(apiGet('chat_api=send'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) { onOk(d.id || 0); } else { onErr(d.error || 'Erreur d’envoi.'); }
        })
        .catch(function () {
          postViaForm('send', { peer_id: p, message: text }, onOk);
        });
    }

    document.getElementById('chatForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || !peer) return;
      sendBtn.disabled = true;
      hybridSend(peer, text, function (serverId) {
        sendBtn.disabled = false;
        input.value = '';
        // Affichage instantané (1 coche). Si le serveur a répondu ok + id,
        // la bulle est confirmée immédiatement (jamais « non envoyé ») ;
        // le sondage la remplace ensuite par le rendu serveur (horodatage).
        appendOptimistic(text, serverId);
        pollMessages();
        // Burst : 3 rafraîchissements rapprochés (~0,7 s d'écart) pour
        // que le destinataire voie la bulle « vu » et le badge à jour
        // quasi instantanément, comme sur un vrai messenger.
        burstLeft = 3;
        refreshStatus();
        broadcastSync({ t: 'sent', peer: peer });
        if (contacts[peer]) { contacts[peer].unread = 0; notifState[peer] = 0; updateUnreadInList(); updateTitle(totalUnread()); }
      }, function (err) {
        sendBtn.disabled = false;
        alert(err);
      });
    });

    // Liste affichée IMMÉDIATEMENT (contacts rendus par PHP), puis
    // rafraîchissement adaptatif : 2 s en conversation, 5 s sinon
    // (onglet actif), 20 s onglet caché. Style messenger.
    renderList();
    refreshStatus();
    rescheduleStatus();

    /* ---- Diffusion instantanée ENTRE ONGLETS du même navigateur ----
       Un envoi (ou une lecture) dans l'onglet A synchronise aussitôt
       l'onglet B : aucune attente du prochain tour de polling. */
    var bc = null;
    try { bc = ('BroadcastChannel' in window) ? new BroadcastChannel('chat-sync') : null; } catch (e) { bc = null; }
    function broadcastSync(data) { try { if (bc) bc.postMessage(data); } catch (e) {} }
    if (bc) {
      bc.onmessage = function () {
        refreshStatus();
        if (convOpen()) pollMessages();
      };
    }

    /* ---- Synchronisation immédiate aux événements de visibilité ----
       Retour sur l'onglet, focus de la fenêtre ou retour réseau :
       on rafraîchit TOUT DE SUITE (zéro attente), puis on recadre le
       rythme adaptatif selon l'état courant. */
    function instantSync() {
      refreshStatus();
      if (convOpen()) pollMessages();
      rescheduleStatus();
    }
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) instantSync(); else rescheduleStatus();
    });
    window.addEventListener('focus', instantSync);
    window.addEventListener('online', instantSync);

    // Ouvrir une conversation = lecture : badge/titre/local remis à zéro.
    var _openConv = openConv;
    openConv = function (id) {
      _openConv(id);
      if (contacts[id]) { contacts[id].unread = 0; notifState[id] = 0; updateUnreadInList(); updateTitle(totalUnread()); }
      // Le destinataire doit voir « vu » tout de suite : burst + diffusion.
      burstLeft = 3;
      refreshStatus();
      broadcastSync({ t: 'read' });
    };

    // Permission bureau demandée au 1er clic (geste utilisateur requis).
    if ('Notification' in window && Notification.permission === 'default') {
      document.addEventListener('click', function askPerm() {
        document.removeEventListener('click', askPerm);
        try { Notification.requestPermission(); } catch (e) { /* ignore */ }
      }, { once: true });
    }
  })();
</script>