/**
 * Gestion des Dossiers — comportements front-end.
 * Aucune dépendance externe (JavaScript natif uniquement).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initSidebarToggle();
    initSidebarCollapse();
    initSidebarFlyout();
    initCaAutoCalc();
    initConfirmActions();
    initNotifDetailModal();
    initNotifDropdown();
    initFlashAutoHide();
    initNewVendeurToggle();
    initBulkSelection();
    initRowSelectionCounters();
    initNotificationBulkSelection();
    initVendeurSelection();
    initOrigineSelection();
    initOrigineEditModal();
    initVendeurEditModal();
    initSuperviseurModal();
    initSuperviseurPasswordUx();
    initRhModal();
    initRhPasswordUx();
    initVendeurPasswordUx();
    initTrashSelection();
    initSalaryCardToggle();
    initSalaryInlineEdit();
    initSalaryFileImport();
    initRhDashboardCharts();
    initUploadSizeGuards();
    initPerformanceNoScroll();
    initPerformanceCaNoScroll();
    initAttachmentPreview();
  });

  /**
   * Cloche de notification de l'en-tête.
   *
   * Desktop : le menu s'ouvre au survol (CSS .notif-wrap:hover), la cloche
   * reste un lien direct vers notifications.php.
   *
   * Mobile (pas de survol) : le clic bascule le menu déroulant au lieu de
   * naviguer. « Tout voir » et chaque élément du menu redirigent toujours
   * vers la page complète des notifications. Clic extérieur ou touche Échap
   * referme le menu.
   */
  function initNotifDropdown() {
    var wrap = document.querySelector('.notif-wrap');
    if (!wrap) return;
    var bell = wrap.querySelector('.notif-bell');
    var dropdown = wrap.querySelector('.notif-dropdown');
    if (!bell || !dropdown) return;

    var setOpen = function (open) {
      dropdown.classList.toggle('open', open);
      bell.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    var isOpen = function () { return dropdown.classList.contains('open'); };

    bell.setAttribute('aria-haspopup', 'true');
    bell.setAttribute('aria-expanded', 'false');

    // Clic sur la cloche : menu sur mobile, navigation sur desktop.
    bell.addEventListener('click', function (event) {
      // Pointeur fin (souris/trackpad) = le survol gère déjà l'ouverture :
      // on laisse le lien fonctionner normalement.
      if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        return;
      }
      // Écran tactile : le survol n'existe pas, on bascule le menu.
      event.preventDefault();
      setOpen(!isOpen());
    });

    // « Tout voir » et les éléments du menu naviguent normalement, puis
    // referment le menu (sécurité si la navigation est interceptée).
    dropdown.addEventListener('click', function (event) {
      var link = event.target.closest('a');
      if (link) { setOpen(false); }
    });

    // Clic extérieur referme le menu (mobile uniquement : sur desktop le
    // menu suit le survol et se referme déjà tout seul).
    document.addEventListener('click', function (event) {
      if (!isOpen()) return;
      if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches && !wrap.contains(event.target)) {
        setOpen(false);
      }
    });

    // Touche Échap referme le menu et rend le focus à la cloche.
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false);
        bell.focus();
      }
    });
  }

  /** Empêche un envoi multipart qui dépasserait la limite affichée par l'application. */
  function initUploadSizeGuards() {
    var forms = document.querySelectorAll('form[enctype="multipart/form-data"]');
    Array.prototype.forEach.call(forms, function (form) {
      var input = form.querySelector('input[type="file"]');
      var maxBytes = parseInt(form.getAttribute('data-upload-max-bytes') || '0', 10);
      if (!input || !maxBytes) return;
      form.addEventListener('submit', function (event) {
        var file = input.files && input.files[0];
        if (file && file.size > maxBytes) {
          event.preventDefault();
          window.alert('Ce fichier dépasse la taille maximale autorisée (' + Math.floor(maxBytes / 1024 / 1024) + ' Mo).');
        }
      });
    });
  }


  /** Bascule le menu latéral en affichage mobile. */
  function initSidebarToggle() {
    var toggle = document.querySelector('[data-sidebar-toggle]');
    var sidebar = document.querySelector('.sidebar');
    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });

    document.addEventListener('click', function (e) {
      if (sidebar.classList.contains('open') &&
          !sidebar.contains(e.target) &&
          !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  /**
   * Bascule le menu latéral entre mode complet et mode icônes seules.
   * L'état est mémorisé dans localStorage pour être conservé entre les pages.
   */
  function initSidebarCollapse() {
    var sidebar = document.getElementById('sidebar');
    var btn = document.querySelector('[data-sidebar-collapse]');
    if (!sidebar || !btn) return;

    var STORAGE_KEY = 'sidebar_collapsed';

    function apply(collapsed) {
      sidebar.classList.toggle('collapsed', collapsed);
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      btn.setAttribute('aria-label', collapsed ? 'Afficher le menu' : 'Réduire le menu');
      btn.title = collapsed ? 'Afficher le menu' : 'Réduire le menu';
      try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (err) { /* stockage indisponible */ }
    }

    var saved = null;
    try { saved = localStorage.getItem(STORAGE_KEY); } catch (err) { /* stockage indisponible */ }
    if (saved === '1') {
      apply(true);
    }

    btn.addEventListener('click', function () {
      apply(!sidebar.classList.contains('collapsed'));
    });
  }

  /**
   * Menu réduit (icônes seules) : au survol d'une icône, un TIROIR sort de
   * derrière la barre et le libellé se découvre par un effet de rideau.
   * Ni bulle flottante, ni pointeur : la capsule (position: fixed, z-index
   * sous la barre) est masquée par la barre tant qu'elle est repliée, et
   * s'ouvre vers la droite en révélant le texte.
   *
   * L'élément est unique, ajouté au <body> : une solution CSS pure serait
   * coupée par l'overflow-x:hidden de .nav-group et ne peut pas connaître
   * la verticale de la ligne survolée. Le libellé réel reste dans le DOM
   * (masqué en visuel par font-size:0) : aria-hidden sur le tiroir
   * uniquement, les lecteurs d'écran ne sont pas impactés.
   */
  function initSidebarFlyout() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var fly = document.createElement('div');
    fly.className = 'sidebar-flyout';
    fly.setAttribute('aria-hidden', 'true');
    var flyText = document.createElement('span');
    flyText.className = 'sidebar-flyout-text';
    fly.appendChild(flyText);
    document.body.appendChild(fly);

    var current = null;

    /** Survol réel (souris/trackpad) sur grand écran uniquement. */
    function canHover() {
      return window.matchMedia('(hover: hover) and (pointer: fine) and (min-width: 961px)').matches;
    }

    function hide() {
      if (current === null) return;
      current = null;
      fly.classList.remove('is-open');
      fly.style.width = '68px'; // se referme : rentre sous la barre
    }

    /** Libellé = noeuds texte directs de l'élément (le SVG est ignoré). */
    function labelFor(el) {
      if (el.classList.contains('btn-logout')) {
        var txt = el.querySelector('.btn-logout-text');
        return txt ? txt.textContent.replace(/\s+/g, ' ').trim() : '';
      }
      var out = '';
      for (var i = 0; i < el.childNodes.length; i++) {
        var node = el.childNodes[i];
        if (node.nodeType === 3) { out += node.nodeValue; }
      }
      return out.replace(/\s+/g, ' ').trim();
    }

    function showFor(el) {
      if (current === el) return; // étiquette déjà affichée pour cette ligne
      if (!sidebar.classList.contains('collapsed') || !canHover()) {
        hide();
        return;
      }
      var label = labelFor(el);
      if (!label) { hide(); return; }
      var rect = el.getBoundingClientRect();
      flyText.textContent = label;
      fly.style.top = Math.round(rect.top) + 'px';
      fly.style.height = Math.round(rect.height) + 'px';
      // Largeur cible : passage à gauche (76) + largeur du texte + marge (18).
      fly.style.width = (76 + flyText.offsetWidth + 18) + 'px';
      fly.classList.add('is-open');
      current = el;
    }

    // Délégation sur tout le menu : entrées, sous-entrées, déconnexion,
    // et aussi les zones vides (interlignes, en-têtes, pied de menu) où
    // l'étiquette doit se refermer.
    sidebar.addEventListener('mouseover', function (event) {
      var el = event.target.closest('.nav-item, .btn-logout');
      if (el) { showFor(el); } else { hide(); }
    });

    // Sortie complète du menu, défilement interne, dépliage ou redimensionnement.
    sidebar.addEventListener('mouseleave', hide);
    var navGroup = sidebar.querySelector('.nav-group');
    if (navGroup) {
      navGroup.addEventListener('scroll', hide, { passive: true });
    }
    var collapseBtn = document.querySelector('[data-sidebar-collapse]');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', hide);
    }
    window.addEventListener('resize', hide, { passive: true });
  }

  /**
   * Recalcule automatiquement le CA annuel selon l'origine du dossier.
   */
  function initCaAutoCalc() {
    var moisInput = document.getElementById('ca_mois');
    var annuelInput = document.getElementById('ca_annuel');
    var origineInput = document.getElementById('ta_origine');
    if (!moisInput || !annuelInput || !origineInput) return;

    function updateAnnualAmount() {
      var monthlyAmount = parseFloat(String(moisInput.value).replace(',', '.'));
      if (isNaN(monthlyAmount)) {
        annuelInput.value = '';
        return;
      }
      annuelInput.value = (Math.round(monthlyAmount * 12 * 100) / 100).toFixed(2);
    }

    moisInput.addEventListener('input', function () {
      updateAnnualAmount();
    });
    origineInput.addEventListener('change', updateAnnualAmount);
    updateAnnualAmount();
  }

  /** Demande une confirmation avant les actions destructives (suppression...). */
  function initConfirmActions() {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        var msg = el.getAttribute('data-confirm') || 'Confirmez-vous cette action ?';
        e.preventDefault();
        var isDanger = !!(el.classList.contains('btn-danger') || (el.querySelector && el.querySelector('.btn-danger')));
        showConfirm(msg, function () {
          var target = el.getAttribute('data-confirm-target');
          if (target) {
            var form = document.getElementById(target);
            if (form) {
              if (form.requestSubmit) form.requestSubmit(); else form.submit();
            }
            return;
          }
          if (el.tagName === 'FORM') { if (el.requestSubmit) el.requestSubmit(); else el.submit(); }
          else if (el.form) { if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit(); }
          else if (el.href) window.location.href = el.href;
        }, isDanger);
      });
    });
  }

  function showConfirm(message, onConfirm, isDanger) {
    var overlay = document.createElement('div');
    overlay.className = 'confirm-modal confirm-modal--anim';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    var danger = isDanger ? ' confirm-dialog--danger' : '';
    overlay.innerHTML = '<div class="confirm-dialog' + danger + '">' +
      '<div class="confirm-icon" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
      '</div>' +
      '<h2>Confirmer l’action</h2>' +
      '<p>' + escapeHtml(message) + '</p>' +
      '<div class="confirm-actions"><button type="button" class="btn btn-outline" data-confirm-cancel>Annuler</button>' +
      '<button type="button" class="btn ' + (isDanger ? 'btn-danger' : 'btn-primary') + '" data-confirm-submit>Confirmer</button></div>' +
      '</div>';
    document.body.appendChild(overlay);
    requestAnimationFrame(function () { overlay.classList.add('confirm-modal--open'); });
    var cancel = overlay.querySelector('[data-confirm-cancel]');
    var submit = overlay.querySelector('[data-confirm-submit]');
    function close() {
      overlay.classList.remove('confirm-modal--open');
      setTimeout(function () { overlay.remove(); }, 160);
      document.removeEventListener('keydown', onKeydown);
    }
    function onKeydown(e) {
      if (e.key === 'Escape') { close(); return; }
      // La touche Entrée ne doit JAMAIS valider seule une action destructive
      // (« Supprimer tous les bulletins de ce mois ») : elle ne fait que
      // rejouer le bouton RÉELLEMENT focalisé dans la boîte de dialogue.
      // Hors dialogue, Entrée retombe sur le comportement natif.
      if (e.key === 'Enter' && overlay.contains(e.target)) {
        e.preventDefault();
        if (e.target === submit) { submit.click(); } else { close(); }
      }
    }
    cancel.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    submit.addEventListener('click', function () { document.removeEventListener('keydown', onKeydown); onConfirm(); });
    document.addEventListener('keydown', onKeydown);
    cancel.focus();
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
  }

  /** Masque automatiquement les messages flash après quelques secondes. */
  function initFlashAutoHide() {
    document.querySelectorAll('.alert[data-autohide]').forEach(function (el) {
      setTimeout(function () {
        el.style.transition = 'opacity 0.4s ease';
        el.style.opacity = '0';
        setTimeout(function () { el.remove(); }, 450);
      }, 5000);
    });
  }

  /** Affiche/masque le mini-formulaire de création d'un nouveau vendeur. */
  function initNewVendeurToggle() {
    var select = document.getElementById('vendeur_id');
    var panel = document.getElementById('new-vendeur-panel');
    var link = document.getElementById('new-vendeur-link');
    if (!link || !panel) return;

    link.addEventListener('click', function (e) {
      e.preventDefault();
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
      if (panel.style.display === 'block') {
        var input = panel.querySelector('input');
        if (input) input.focus();
      }
    });
  }

  /** Gere la selection multiple des dossiers dans la liste. */
  function initBulkSelection() {
    var selectAll = document.querySelector('[data-select-all]');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-dossier-select]'));
    var actions = document.querySelector('[data-bulk-actions]');
    var count = document.querySelector('[data-selection-count]');
    var editButton = document.querySelector('[data-bulk-edit]');
    var deleteButton = document.querySelector('[data-bulk-delete]');
    var deleteForm = document.querySelector('[data-bulk-delete-form]');
    if (!selectAll || !checkboxes.length || !actions || !count) return;

    function selected() {
      return checkboxes.filter(function (checkbox) { return checkbox.checked; });
    }

    /**
     * Met en evidence la LIGNE ENTIERE d'un dossier selectionne (toutes ses
     * cellules, y compris la cellule de la case a cocher). La classe est posee
     * ou retiree par le JavaScript — et non par une regle CSS `:has()` —
     * afin de rester compatible avec tous les navigateurs. Appelee par
     * refresh(), elle couvre donc : le clic sur une case, « tout selectionner »,
     * la deselection et le chargement initial de la page.
     */
    function syncRowHighlight() {
      checkboxes.forEach(function (checkbox) {
        var row = checkbox.closest ? checkbox.closest('tr') : null;
        if (!row) return;
        if (checkbox.checked) {
          row.classList.add('row-selected');
        } else {
          row.classList.remove('row-selected');
        }
      });
    }

    function refresh() {
      var selectedItems = selected();
      var total = selectedItems.length;
      count.textContent = total + (total === 1 ? ' dossier sélectionné' : ' dossiers sélectionnés');
      selectAll.checked = total === checkboxes.length;
      selectAll.indeterminate = total > 0 && total < checkboxes.length;
      if (editButton) editButton.disabled = total !== 1;
      if (deleteButton) deleteButton.disabled = total === 0;
      syncRowHighlight();
    }

    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
      refresh();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', refresh); });

    if (editButton) {
      editButton.addEventListener('click', function () {
        var selectedItems = selected();
        if (selectedItems.length === 1) {
          showConfirm('Ouvrir le dossier sélectionné pour le modifier ?', function () {
            window.location.href = 'dossier_form.php?id=' + encodeURIComponent(selectedItems[0].value);
          });
        }
      });
    }
    if (deleteButton && deleteForm) {
      deleteButton.addEventListener('click', function () {
        var selectedItems = selected();
        if (!selectedItems.length) return;
        showConfirm('Supprimer définitivement les ' + selectedItems.length + ' dossiers sélectionnés ?', function () {
          selectedItems.forEach(function (checkbox) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dossier_ids[]';
            input.value = checkbox.value;
            deleteForm.appendChild(input);
          });
          deleteForm.submit();
        });
      });
    }
    refresh();
  }

  /**
   * Compteurs « lignes cochées / lignes affichées » pour le superviseur et le
   * vendeur. Purement COSMETIQUE et CLIENT-SIDE : aucune requête, aucun
   * formulaire, aucune modification en base — l'utilisateur peut uniquement
   * cocher/decocher une case. Le nombre de lignes affichées est figé par le
   * PHP (une seule page a la fois) ; seul le compteur de coches evolue.
   */
  function initRowSelectionCounters() {
    var box = document.querySelector('[data-row-selection-counters]');
    if (!box) return;

    var selectAll = document.querySelector('[data-row-select-all]');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-row-select]'));
    var checkedCount = box.querySelector('[data-row-checked-count]');
    var displayedCount = box.querySelector('[data-row-displayed-count]');
    if (!checkedCount || !checkboxes.length) return;

    // Le total affiche peut desyncrhoniser si le DOM evolue : on recalcule
    // depuis le nombre de cases, qui est par definition le nombre de lignes.
    if (displayedCount) {
      displayedCount.textContent = String(checkboxes.length);
    }

    function refresh() {
      var total = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
      checkedCount.textContent = String(total);
      if (selectAll) {
        selectAll.checked = total === checkboxes.length;
        selectAll.indeterminate = total > 0 && total < checkboxes.length;
      }
      // Mise en evidence de la ligne entiere, coherente avec l'existant.
      checkboxes.forEach(function (checkbox) {
        var row = checkbox.closest ? checkbox.closest('tr') : null;
        if (!row) return;
        if (checkbox.checked) {
          row.classList.add('row-selected');
        } else {
          row.classList.remove('row-selected');
        }
      });
    }

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        refresh();
      });
    }
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', refresh); });
    refresh();
  }

  /** Gere la selection multiple des notifications sur la page courante. */
  function initNotificationBulkSelection() {
    var selectAll = document.querySelector('[data-notif-select-all]');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-notif-select]'));
    var count = document.querySelector('[data-notif-selection-count]');
    var deleteButton = document.querySelector('[data-notif-delete-selected]');
    if (!selectAll || !checkboxes.length || !count) return;

    function refresh() {
      var selected = checkboxes.filter(function (checkbox) { return checkbox.checked; });
      var total = selected.length;
      count.textContent = total + (total === 1 ? ' notification sélectionnée' : ' notifications sélectionnées');
      selectAll.checked = total === checkboxes.length;
      selectAll.indeterminate = total > 0 && total < checkboxes.length;
      if (deleteButton) deleteButton.disabled = total === 0;
      checkboxes.forEach(function (checkbox) {
        var row = checkbox.closest ? checkbox.closest('tr') : null;
        if (row) row.classList.toggle('row-selected', checkbox.checked);
      });
    }

    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
      refresh();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', refresh); });
    refresh();
  }

  /** Gere la selection multiple des vendeurs dans le referentiel. */
  function initVendeurSelection() {
    var selectAll = document.querySelector('[data-vendeur-select-all]');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-vendeur-select]'));
    var count = document.querySelector('[data-vendeur-selection-count]');
    var deleteButton = document.querySelector('[data-vendeur-delete]');
    var deleteForm = document.querySelector('[data-vendeur-delete-form]');
    if (!selectAll || !checkboxes.length || !count) return;

    function selected() { return checkboxes.filter(function (checkbox) { return checkbox.checked; }); }
    function refresh() {
      var total = selected().length;
      count.textContent = total + (total === 1 ? ' vendeur sélectionné' : ' vendeurs sélectionnés');
      selectAll.checked = total === checkboxes.length;
      selectAll.indeterminate = total > 0 && total < checkboxes.length;
      if (deleteButton) deleteButton.disabled = total === 0;
    }
    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
      refresh();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', refresh); });
    if (deleteButton && deleteForm) deleteButton.addEventListener('click', function () {
      var items = selected();
      if (!items.length) return;
      showConfirm('Supprimer définitivement les ' + items.length + ' vendeurs sélectionnés ?', function () {
        items.forEach(function (checkbox) {
          var input = document.createElement('input');
          input.type = 'hidden'; input.name = 'vendeur_ids[]'; input.value = checkbox.value;
          deleteForm.appendChild(input);
        });
        deleteForm.submit();
      });
    });
    refresh();
  }

  /**
   * Popup vendeur : création ET modification dans le même dialogue.
   * Champs : nom, identifiant, e-mail, statut (tous les vendeurs sont
   * connectables) et mot de passe (obligatoire à la création). L'action
   * du formulaire bascule entre vendeur_save.php et vendeur_update.php
   * selon le mode.
   */
  function initVendeurEditModal() {
    var modal = document.getElementById('vendeur-modal');
    var form = document.getElementById('vendeur-modal-form');
    var idInput = document.getElementById('vendeur-modal-id');
    var nomInput = document.getElementById('vendeur-modal-nom');
    var usernameInput = document.getElementById('vendeur-modal-username');
    var emailInput = document.getElementById('vendeur-modal-email');
    var activeInput = document.getElementById('vendeur-modal-active');
    var superviseInput = document.getElementById('vendeur-modal-supervise');
    var passwordInput = document.getElementById('vendeur-modal-password');
    var title = document.getElementById('vendeur-modal-title');
    if (!modal || !form || !idInput || !nomInput) return;

    function setMode(isEdit) {
      var action = isEdit
        ? form.getAttribute('data-edit-action')
        : form.getAttribute('data-create-action');
      if (action) form.action = action;
      if (title) title.textContent = isEdit ? 'Modifier le vendeur' : 'Nouveau vendeur';
      if (passwordInput) passwordInput.required = !isEdit;
      if (usernameInput) usernameInput.required = false;
    }

    function openModal(data) {
      var isEdit = !!data.id;
      setMode(isEdit);
      idInput.value = isEdit ? String(data.id) : '';
      nomInput.value = data.nom || '';
      if (usernameInput) usernameInput.value = data.username || '';
      if (emailInput) emailInput.value = data.email || '';
      if (activeInput) activeInput.value = (data.active === 0 || data.active === '0') ? '0' : '1';
      // Tous les vendeurs sont connectables : le champ reste toujours à 1.
      if (superviseInput) superviseInput.value = '1';
      if (passwordInput) passwordInput.value = '';
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(function () { nomInput.focus(); nomInput.select(); }, 50);
    }

    function closeModal() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
      }
    }

    var newButton = document.getElementById('vendeur-new-btn');
    if (newButton) {
      newButton.addEventListener('click', function () {
        openModal({ id: '', nom: '', username: '', email: '', active: 1, supervise: 1 });
      });
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;

      var editButton = target.closest('[data-vendeur-edit]');
      if (editButton) {
        event.preventDefault();
        openModal({
          id: editButton.getAttribute('data-id') || '',
          nom: editButton.getAttribute('data-nom') || '',
          username: editButton.getAttribute('data-username') || '',
          email: editButton.getAttribute('data-email') || '',
          active: editButton.getAttribute('data-active'),
          supervise: 1
        });
        return;
      }

      if (target.closest('#vendeur-modal-cancel')) {
        closeModal();
        return;
      }

      if (target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.style.display === 'flex') {
        closeModal();
      }
    });
  }

  /** Gere la selection multiple des origines dans le referentiel. */
  function initOrigineSelection() {
    var selectAll = document.querySelector('[data-origine-select-all]');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-origine-select]'));
    var count = document.querySelector('[data-origine-selection-count]');
    var deleteButton = document.querySelector('[data-origine-delete-selected]');
    var deleteForm = document.querySelector('[data-origine-delete-form]');
    if (!selectAll || !checkboxes.length || !count) return;

    function selected() { return checkboxes.filter(function (checkbox) { return checkbox.checked; }); }

    function refresh() {
      var total = selected().length;
      count.textContent = total + (total === 1 ? ' origine sélectionnée' : ' origines sélectionnées');
      selectAll.checked = total === checkboxes.length;
      selectAll.indeterminate = total > 0 && total < checkboxes.length;
      if (deleteButton) deleteButton.disabled = total === 0;
    }

    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
      refresh();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', refresh); });

    if (deleteButton && deleteForm) deleteButton.addEventListener('click', function () {
      var items = selected();
      if (!items.length) return;
      showConfirm('Supprimer définitivement les ' + items.length + ' origines sélectionnées ?', function () {
        items.forEach(function (checkbox) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'origine_ids[]';
          input.value = checkbox.value;
          deleteForm.appendChild(input);
        });
        deleteForm.submit();
      });
    });

    refresh();
  }

  /** Ouvre la popup de modification d'une origine. */
  function initOrigineEditModal() {
    var modal = document.getElementById('origine-edit-modal');
    var idInput = document.getElementById('origine-edit-id');
    var nameInput = document.getElementById('origine-edit-name');
    if (!modal || !idInput || !nameInput) return;

    function openModal(id, nom) {
      idInput.value = String(id || '');
      nameInput.value = String(nom || '');
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(function () {
        nameInput.focus();
        nameInput.select();
      }, 50);
    }

    function closeModal() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
      }
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;

      var editButton = target.closest('[data-origine-edit]');
      if (editButton) {
        event.preventDefault();
        openModal(editButton.getAttribute('data-id') || '', editButton.getAttribute('data-nom') || '');
        return;
      }

      if (target.closest('[data-origine-close]')) {
        closeModal();
        return;
      }

      if (target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.style.display === 'flex') {
        closeModal();
      }
    });
  }

  /** Gere la selection multiple des dossiers dans la corbeille. */
  function initTrashSelection() {
    var selectAll = document.querySelector('[data-trash-select-all]');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-trash-select]'));
    var count = document.querySelector('[data-trash-selection-count]');
    var deleteButton = document.querySelector('[data-trash-delete-selected]');
    var deleteForm = document.querySelector('[data-trash-delete-form]');
    if (!selectAll || !checkboxes.length) return;

    function selected() {
      return checkboxes.filter(function (checkbox) { return checkbox.checked; });
    }

    function refresh() {
      var selectedItems = selected();
      var total = selectedItems.length;
      if (count) {
        count.textContent = total + (total === 1 ? ' dossier sélectionné' : ' dossiers sélectionnés');
      }
      selectAll.checked = total === checkboxes.length;
      selectAll.indeterminate = total > 0 && total < checkboxes.length;
      if (deleteButton) deleteButton.disabled = total === 0;
    }

    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
      refresh();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', refresh); });

    if (deleteButton && deleteForm) {
      deleteButton.addEventListener('click', function () {
        var selectedItems = selected();
        if (!selectedItems.length) return;
        showConfirm('Supprimer définitivement les ' + selectedItems.length + ' dossier(s) sélectionné(s) de la corbeille ?', function () {
          selectedItems.forEach(function (checkbox) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'trash_ids[]';
            input.value = checkbox.value;
            deleteForm.appendChild(input);
          });
          deleteForm.submit();
        });
      });
    }
    refresh();
  }

  function initSalaryFileImport() {
    var input = document.querySelector('[data-salary-file]');
    var dropzone = document.querySelector('[data-salary-dropzone]');
    var fileName = document.querySelector('[data-salary-file-name]');
    if (!input || !dropzone || !fileName) return;

    function updateFileName() {
      fileName.textContent = input.files.length ? input.files[0].name : 'Choisir un fichier XLSX';
      dropzone.classList.toggle('has-file', input.files.length > 0);
    }

    input.addEventListener('change', updateFileName);
    ['dragenter', 'dragover'].forEach(function (eventName) {
      dropzone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
      });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
      dropzone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
      });
    });
    dropzone.addEventListener('drop', function (event) {
      if (event.dataTransfer.files.length) {
        input.files = event.dataTransfer.files;
        updateFileName();
      }
    });
  }

  /** Gère les graphiques du tableau de bord RH. */
  function initRhDashboardCharts() {
    var salaryCanvas = document.getElementById('salaryChart');
    var hoursCanvas = document.getElementById('hoursChart');
    var attendanceCanvases = document.querySelectorAll('[data-static-attendance]');
    if (!salaryCanvas && !hoursCanvas && !attendanceCanvases.length) return;

    var salaryData = salaryCanvas ? JSON.parse(salaryCanvas.getAttribute('data-values') || '[]') : [];
    var hoursData = hoursCanvas ? JSON.parse(hoursCanvas.getAttribute('data-values') || '[]') : [];
    var monthLabels = salaryCanvas ? JSON.parse(salaryCanvas.getAttribute('data-labels') || '[]') : [];

    function setupCanvas(canvas) {
      if (!canvas) return null;
      var rect = canvas.parentElement.getBoundingClientRect();
      var dpr = window.devicePixelRatio || 1;
      canvas.width = rect.width * dpr;
      canvas.height = 260 * dpr;
      var ctx = canvas.getContext('2d');
      ctx.scale(dpr, dpr);
      return { ctx: ctx, width: rect.width, height: 260 };
    }

    function drawChart(canvas, data, color, fillColor, labels) {
      var ctxInfo = setupCanvas(canvas);
      if (!ctxInfo) return;
      var ctx = ctxInfo.ctx;
      var w = ctxInfo.width;
      var h = ctxInfo.height;
      var pad = { left: 45, right: 16, top: 16, bottom: 36 };
      var max = Math.max(1, ...data);
      var gw = w - pad.left - pad.right;
      var gh = h - pad.top - pad.bottom;

      ctx.clearRect(0, 0, w, h);
      ctx.strokeStyle = '#E2DBC9';
      ctx.lineWidth = 1;
      ctx.font = '11px -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif';
      ctx.fillStyle = '#8A93A0';

      for (var i = 0; i <= 4; i++) {
        var y = pad.top + (gh * i / 4);
        ctx.beginPath();
        ctx.moveTo(pad.left, y);
        ctx.lineTo(w - pad.right, y);
        ctx.stroke();
        var val = max - (max * i / 4);
        ctx.fillText(val.toFixed(0), 2, y + 4);
      }

      var points = [];
      for (var i = 0; i < data.length; i++) {
        var x = pad.left + (gw * i / (data.length - 1 || 1));
        var y = pad.top + gh - (data[i] / max) * gh;
        points.push({ x: x, y: y });
      }

      var grad = ctx.createLinearGradient(0, pad.top, 0, h - pad.bottom);
      grad.addColorStop(0, fillColor);
      grad.addColorStop(1, '#ffffff00');
      ctx.beginPath();
      ctx.moveTo(points[0].x, points[0].y);
      for (var i = 1; i < points.length; i++) {
        ctx.lineTo(points[i].x, points[i].y);
      }
      ctx.lineTo(points[points.length - 1].x, h - pad.bottom);
      ctx.lineTo(points[0].x, h - pad.bottom);
      ctx.closePath();
      ctx.fillStyle = grad;
      ctx.fill();

      ctx.beginPath();
      ctx.moveTo(points[0].x, points[0].y);
      for (var i = 1; i < points.length; i++) {
        ctx.lineTo(points[i].x, points[i].y);
      }
      var lineColor = color;
      if (color === 'turquoise-gradient') {
        lineColor = ctx.createLinearGradient(pad.left, 0, w - pad.right, 0);
        lineColor.addColorStop(0, '#0A9FAD');
        lineColor.addColorStop(1, '#12D6D6');
      }
      ctx.strokeStyle = lineColor;
      ctx.lineWidth = 2.5;
      ctx.stroke();

      points.forEach(function (p, i) {
        ctx.beginPath();
        ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = lineColor;
        ctx.stroke();
        if (i % 2 === 0 || w < 600) {
          ctx.fillStyle = '#64748b';
          ctx.fillText((labels[i] || '').slice(0, 3), p.x - 10, h - 12);
        }
      });
    }

    if (salaryCanvas && salaryData.length) {
      drawChart(salaryCanvas, salaryData, '#012B5E', 'rgba(1,43,94,0.14)', monthLabels);
      window.addEventListener('resize', function () {
        drawChart(salaryCanvas, salaryData, '#012B5E', 'rgba(1,43,94,0.14)', monthLabels);
      });
    }

    if (hoursCanvas && hoursData.length) {
      drawChart(hoursCanvas, hoursData, 'turquoise-gradient', 'rgba(10,159,173,0.16)', monthLabels);
      window.addEventListener('resize', function () {
        drawChart(hoursCanvas, hoursData, 'turquoise-gradient', 'rgba(10,159,173,0.16)', monthLabels);
      });
    }

    attendanceCanvases.forEach(function (canvas) {
      var data = JSON.parse(canvas.getAttribute('data-values') || '[]');
      var labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
      if (!data.length) return;
      var isAbsence = canvas.id === 'absenceChart';
      var lineColor = canvas.getAttribute('data-chart-color') || (isAbsence ? '#012B5E' : 'turquoise-gradient');
      var fillColor = canvas.getAttribute('data-chart-fill') || (isAbsence ? 'rgba(1,43,94,0.14)' : 'rgba(10,159,173,0.16)');
      function renderAttendance() {
        drawChart(canvas, data, lineColor, fillColor, labels);
      }
      renderAttendance();
      window.addEventListener('resize', renderAttendance);
    });
  }

  /**
   * Met à jour "Performance par vendeur" sans rechargement ni déplacement du scroll.
   * En cas d'échec réseau, le formulaire soumet normalement (avec ancre #performance-vendeurs-card).
   */
  function initPerformanceNoScroll() {
    var form = document.querySelector('[data-performance-form]');
    if (!form) return;
    var card = document.getElementById('performance-vendeurs-card') || form.closest('.card');
    var tbody = card ? card.querySelector('[data-performance-tbody]') : null;
    var submitBtn = form.querySelector('[data-performance-submit]');
    var statusEl = form.querySelector('[data-performance-status]');
    if (!card || !tbody) return;

    // Repli sans JS/AJAX : rester ancré sur la carte après rechargement.
    form.setAttribute('action', '#performance-vendeurs-card');

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var params = new URLSearchParams(new FormData(form));
      params.set('ajax', 'performance');
      var url = window.location.pathname + '?' + params.toString();

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Chargement...';
      }
      if (statusEl) statusEl.textContent = '';

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (data) {
          if (!data || !data.ok) throw new Error('bad-response');
          tbody.innerHTML = data.rowsHtml || '';
          var nextUrl = window.location.pathname + '?' + params.toString().replace(/(^|&)ajax=performance(&|$)/, function (m, a, b) { return a && b ? a : ''; });
          nextUrl = nextUrl.replace(/\?$/, '');
          try { window.history.replaceState(null, '', nextUrl); } catch (e) {}
          if (statusEl) statusEl.textContent = 'Mis à jour.';
        })
        .catch(function () {
          // Échec : soumission classique, l'ancre garde la position sur la carte.
          form.submit();
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Actualiser';
          }
        });
    });
  }

  /**
   * Met à jour « Performance vendeur par chiffre d'affaire » sans rechargement
   * ni déplacement du scroll (le tableau est remplacé sur place via AJAX).
   * En cas d'échec réseau, le formulaire soumet normalement, avec l'ancre
   * #performance-ca-card pour rester positionné sur la carte après rechargement.
   */
  function initPerformanceCaNoScroll() {
    var form = document.querySelector('[data-performance-ca-form]');
    if (!form) return;
    var card = document.getElementById('performance-ca-card') || form.closest('.card');
    var tbody = card ? card.querySelector('[data-performance-ca-tbody]') : null;
    var submitBtn = form.querySelector('[data-performance-ca-submit]');
    var statusEl = form.querySelector('[data-performance-ca-status]');
    var subtitleEl = document.getElementById('performance-ca-subtitle');
    if (!card || !tbody) return;

    // Repli sans JS/AJAX : rester ancré sur la carte après rechargement.
    form.setAttribute('action', '#performance-ca-card');

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var params = new URLSearchParams(new FormData(form));
      params.set('ajax', 'performance_ca');
      var url = window.location.pathname + '?' + params.toString();

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Chargement...';
      }
      if (statusEl) statusEl.textContent = '';

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (data) {
          if (!data || !data.ok) throw new Error('bad-response');
          tbody.innerHTML = data.caRowsHtml || '';
          if (subtitleEl && data.caSubtitle) subtitleEl.textContent = data.caSubtitle;
          var nextUrl = window.location.pathname + '?' + params.toString().replace(/(^|&)ajax=performance_ca(&|$)/, function (m, a, b) { return a && b ? a : ''; });
          nextUrl = nextUrl.replace(/\?$/, '');
          try { window.history.replaceState(null, '', nextUrl); } catch (e) {}
          if (statusEl) statusEl.textContent = 'Mis à jour.';
        })
        .catch(function () {
          // Échec : soumission classique, l'ancre garde la position sur la carte.
          form.submit();
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Actualiser';
          }
        });
    });
  }

  /** Gère l’ouverture/fermeture du formulaire de saisie manuelle d'un salaire. */
  function initSalaryCardToggle() {
    var newSalaryBtn = document.getElementById('newSalaryBtn');
    var manualSalaryCard = document.getElementById('manualSalaryCard');
    var manualSelect = document.getElementById('manualEmpSelect');
    var manualHours = document.querySelector('[name="paid_hours"]');
    var manualDays = document.querySelector('[name="paid_days"]');
    var manualRate = document.getElementById('manualRate');
    var manualCalc = document.getElementById('manualCalcDisplay');

    function updateManualPreview() {
      if (!manualSelect || !manualRate || !manualCalc) return;
      var option = manualSelect.options[manualSelect.selectedIndex];
      var rate = parseFloat(option && option.getAttribute('data-rate')) || 0;
      var position = option && option.getAttribute('data-position');
      var base = position === 'Responsable' ? manualDays : manualHours;
      var amount = base ? parseFloat(String(base.value).replace(',', '.')) || 0 : 0;
      manualRate.value = rate.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TND/h';
      manualCalc.value = (amount * rate).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TND';
    }

    if (manualSelect && (manualHours || manualDays)) {
      manualSelect.addEventListener('change', updateManualPreview);
      if (manualHours) manualHours.addEventListener('input', updateManualPreview);
      if (manualDays) manualDays.addEventListener('input', updateManualPreview);
      updateManualPreview();
    }

    if (newSalaryBtn && manualSalaryCard) {
      newSalaryBtn.addEventListener('click', function () {
        var isHidden = manualSalaryCard.style.display === 'none' || manualSalaryCard.style.display === '';
        manualSalaryCard.style.display = isHidden ? 'block' : 'none';
      });
    }

    document.addEventListener('click', function (event) {
      if (!event.target || !event.target.closest) return;
      var closeButton = event.target.closest('[data-close-salary-card]');
      if (closeButton) {
        if (manualSalaryCard) {
          manualSalaryCard.style.display = 'none';
        }
      }
    });
  }

  /** Gère l’édition inline des salaires dans la liste. */
  function initSalaryInlineEdit() {
    document.addEventListener('click', function (event) {
      if (!event.target || !event.target.closest) return;

      var openButton = event.target.closest('[data-open-inline-edit]');
      if (openButton) {
        var row = openButton.closest('tr');
        var editRow = row && row.nextElementSibling;
        var editCard = editRow && editRow.querySelector('.edit-inline-card');
        var editForm = editRow && editRow.querySelector('.inline-edit-form');
        if (editRow && editRow.classList.contains('edit-row') && editCard && editForm) {
          var editCell = editForm.parentElement;
          var modal = document.createElement('div');
          var closeButton = document.createElement('button');

          modal.className = 'salary-edit-modal';
          modal.setAttribute('role', 'dialog');
          modal.setAttribute('aria-modal', 'true');
          modal.setAttribute('aria-label', 'Modifier le salaire');
          closeButton.type = 'button';
          closeButton.className = 'salary-edit-modal-close';
          closeButton.setAttribute('aria-label', 'Fermer');
          closeButton.textContent = 'X';
          modal.appendChild(closeButton);
          modal.appendChild(editForm);
          document.body.appendChild(modal);
          editRow.style.display = 'none';
          row.style.display = 'none';
          editForm._salaryModal = modal;
          editForm._salaryEditCell = editCell;
          updateInlinePreview(editForm);
        }
      }

      var cancelButton = event.target.closest('[data-cancel-inline-edit]');
      if (cancelButton) {
        var card = cancelButton.closest('.edit-inline-card');
        closeSalaryEditModal(card && card.closest('.inline-edit-form'));
      }

      var closeModalButton = event.target.closest('.salary-edit-modal-close');
      if (closeModalButton) {
        var modalForm = closeModalButton.parentElement.querySelector('.inline-edit-form');
        closeSalaryEditModal(modalForm);
      }
    });

    function closeSalaryEditModal(form) {
      if (!form || !form._salaryModal || !form._salaryEditCell) return;
      var modal = form._salaryModal;
      var cell = form._salaryEditCell;
      var row = cell.closest('tr');
      var previousRow = row && row.previousElementSibling;
      cell.appendChild(form);
      modal.remove();
      form._salaryModal = null;
      form._salaryEditCell = null;
      if (row) row.style.display = 'none';
      if (previousRow && previousRow.classList.contains('data-row')) previousRow.style.display = '';
    }

    function updateInlinePreview(form) {
      if (!form) return;
      var position = (form.getAttribute('data-position') || 'Agent');
      var paidHours = form.querySelector('input[name="paid_hours"]');
      var paidDays = form.querySelector('input[name="paid_days"]');
      var rate = form.querySelector('input[name="hourly_rate_used"]');
      var preview = form.querySelector('.inline-preview');
      if (!rate || !preview) return;

      function calc() {
        var baseField = position === 'Responsable' ? paidDays : paidHours;
        var base = baseField ? (parseFloat(baseField.value) || 0) : 0;
        var r = parseFloat(rate.value) || 0;
        preview.textContent = (base * r).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TND';
      }

      if (paidHours) paidHours.addEventListener('input', calc);
      if (paidDays) paidDays.addEventListener('input', calc);
      rate.addEventListener('input', calc);
      calc();
    }
  }

  /**
   * Fabrique de la popup « nouveau compte / modification » d'un rôle de compte.
   *
   * Utilisée telle quelle par les superviseurs ET par les comptes RH : les deux
   * écrans partagent le même design, les mêmes champs et le même comportement.
   * Tout est paramétré par le préfixe DOM (`pre`) : les IDs doivent valoir
   * « {pre}-modal », « {pre}-form », « {pre}-modal-nom »…
   */
  function initAccountModal(cfg) {
    var id = cfg.ids;
    var modal = document.getElementById(id.modal);
    var form = document.getElementById(id.form);
    if (!modal || !form) return;
    var modalTitle = document.getElementById(id.title);
    var idInput = document.getElementById(id.idField);
    var extraIdInput = id.extraIdField ? document.getElementById(id.extraIdField) : null;
    var nomInput = document.getElementById(id.nom);
    var usernameInput = document.getElementById(id.username);
    var emailInput = document.getElementById(id.email);
    var activeInput = document.getElementById(id.active);
    var passwordInput = document.getElementById(id.password);
    var submitButton = document.getElementById(id.submit);
    var cancelButton = id.cancel ? document.getElementById(id.cancel) : null;
    var helpText = document.getElementById(id.help);
    var btnNew = document.getElementById(cfg.newButtonId);
    if (!idInput || !nomInput || !usernameInput) return;

    var strength = document.getElementById(id.strength);
    var bar = strength ? strength.querySelector('.password-strength-bar') : null;
    var strengthLabel = strength ? strength.querySelector('.password-strength-label') : null;
    var hintsWrap = document.getElementById(id.hintsWrap);

    function resetStrength() {
      if (bar) {
        var segs = bar.querySelectorAll('.segment');
        for (var i = 0; i < segs.length; i++) segs[i].className = 'segment animate-' + i;
      }
      if (strengthLabel) strengthLabel.textContent = 'Très faible';
      if (hintsWrap) hintsWrap.style.display = 'none';
    }

    function openModal(mid, nom, username, email, isActive) {
      var isEdit = !!mid;
      idInput.value = String(mid || '');
      if (extraIdInput) extraIdInput.value = String(mid || '');
      nomInput.value = String(nom || '');
      usernameInput.value = String(username || '');
      if (emailInput) emailInput.value = String(email || '');
      if (activeInput) activeInput.value = isActive ? '1' : '0';
      if (passwordInput) {
        passwordInput.value = '';
        passwordInput.type = 'password';
        passwordInput.required = !isEdit;
        var toggle = document.getElementById(id.toggle);
        if (toggle) {
          toggle.textContent = 'Afficher';
          toggle.setAttribute('aria-pressed', 'false');
        }
        resetStrength();
      }
      if (helpText) {
        helpText.textContent = isEdit
          ? '12 caractères minimum, avec une majuscule, une minuscule, un chiffre et un caractère spécial. Laisser vide pour conserver le mot de passe actuel.'
          : '12 caractères minimum, avec une majuscule, une minuscule, un chiffre et un caractère spécial.';
      }
      if (modalTitle) modalTitle.textContent = isEdit ? cfg.editTitle : cfg.createTitle;
      if (submitButton) submitButton.textContent = isEdit ? 'Enregistrer' : 'Créer';
      form.action = isEdit
        ? (form.getAttribute('data-edit-action') || cfg.defaultEditAction)
        : (form.getAttribute('data-create-action') || cfg.defaultCreateAction);
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(function () { nomInput.focus(); nomInput.select(); }, 50);
    }

    function closeModal() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (document.activeElement && typeof document.activeElement.blur === 'function') document.activeElement.blur();
    }

    if (btnNew) {
      btnNew.addEventListener('click', function () { openModal('', '', '', '', true); });
    }
    if (cancelButton) {
      cancelButton.addEventListener('click', closeModal);
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;
      var editButton = target.closest(cfg.editAttr);
      if (editButton) {
        event.preventDefault();
        openModal(
          editButton.getAttribute('data-id') || '',
          editButton.getAttribute('data-nom') || '',
          editButton.getAttribute('data-username') || '',
          editButton.getAttribute('data-email') || '',
          editButton.getAttribute('data-active') === '1'
        );
        return;
      }
      if (target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });
  }

  /** Initialise la popup de création/modification d'un superviseur. */
  function initSuperviseurModal() {
    initAccountModal({
      newButtonId: 'btn-new-superviseur',
      createTitle: 'Nouveau superviseur', editTitle: 'Modifier le superviseur',
      editAttr: '[data-superviseur-edit]',
      defaultCreateAction: '/actions/superviseur_save.php',
      defaultEditAction: '/actions/superviseur_update.php',
      // IDs historiques de la popup superviseur (conservés à l'identique).
      ids: {
        modal: 'superviseur-modal', form: 'superviseur-form',
        title: 'modal-title', idField: 'modal-id', extraIdField: 'password-id',
        nom: 'modal-nom', username: 'modal-username', email: 'modal-email',
        active: 'modal-active', password: 'modal-password',
        submit: 'modal-submit', cancel: 'modal-cancel',
        toggle: 'password-toggle', help: 'password-help',
        strength: 'password-strength', hintsWrap: 'password-strength-hints'
      }
    });
  }

  /** Initialise la popup de création/modification d'un compte RH. */
  function initRhModal() {
    initAccountModal({
      newButtonId: 'btn-new-rh',
      createTitle: 'Nouveau compte RH', editTitle: 'Modifier le compte RH',
      editAttr: '[data-rh-edit]',
      defaultCreateAction: '/actions/rh_save.php',
      defaultEditAction: '/actions/rh_update.php',
      // Mêmes IDs que la popup superviseur, simplement préfixés par « rh- ».
      ids: {
        modal: 'rh-modal', form: 'rh-form',
        title: 'rh-modal-title', idField: 'rh-modal-id',
        nom: 'rh-modal-nom', username: 'rh-modal-username', email: 'rh-modal-email',
        active: 'rh-modal-active', password: 'rh-modal-password',
        submit: 'rh-modal-submit', cancel: 'rh-modal-cancel',
        toggle: 'rh-password-toggle', help: 'rh-password-help',
        strength: 'rh-password-strength', hintsWrap: 'rh-password-strength-hints'
      }
    });
  }

  /**
   * Améliore l'UX d'un champ mot de passe : bascule afficher/masquer + robustesse.
   * Le préfixe `pfx` permet de réutiliser exactement le même comportement dans
   * plusieurs popups (superviseur, vendeur, RH) : chaque popup possède ses
   * propres IDs préfixés (« modal-password » / « rh-modal-password »).
   */
  function initPasswordUxFor(passwordId, pfx) {
    pfx = pfx || 'password';
    var pwd = document.getElementById(passwordId);
    var toggle = document.getElementById(pfx + '-toggle');
    if (!pwd || !toggle) return;

    var strength = document.getElementById(pfx + '-strength');
    if (!strength) {
      strength = document.createElement('div');
      strength.id = pfx + '-strength';
      strength.className = 'password-strength';
      strength.setAttribute('aria-live', 'polite');
      pwd.parentNode.parentNode.appendChild(strength);
    }

    var barContainer = strength.querySelector('.password-strength-bar');
    var label = strength.querySelector('.password-strength-label');
    var hintsWrapper = document.getElementById(pfx + '-strength-hints');
    var hintsList = document.getElementById(pfx + '-hints-list');
    if (!barContainer) {
      barContainer = document.createElement('div');
      barContainer.className = 'password-strength-bar';
      strength.appendChild(barContainer);
    }
    if (!label) {
      label = document.createElement('span');
      label.className = 'password-strength-label';
      strength.appendChild(label);
    }
    if (!hintsWrapper) {
      hintsWrapper = document.createElement('div');
      hintsWrapper.id = pfx + '-strength-hints';
      hintsWrapper.className = 'password-strength-hints';
      hintsWrapper.style.display = 'none';
      hintsList = document.createElement('ul');
      hintsList.id = pfx + '-hints-list';
      hintsWrapper.appendChild(hintsList);
      strength.parentNode.appendChild(hintsWrapper);
    } else {
      hintsList = document.getElementById(pfx + '-hints-list');
    }

    // ensure 5 segments exist
    var segments = barContainer.querySelectorAll('.segment');
    if (!segments || segments.length !== 5) {
      barContainer.innerHTML = '';
      for (var i = 0; i < 5; i++) {
        var s = document.createElement('div');
        s.className = 'segment';
        barContainer.appendChild(s);
      }
      segments = barContainer.querySelectorAll('.segment');
    }

    toggle.addEventListener('click', function () {
      var type = pwd.getAttribute('type') === 'password' ? 'text' : 'password';
      pwd.setAttribute('type', type);
      var pressed = pwd.getAttribute('type') === 'text';
      toggle.setAttribute('aria-pressed', pressed ? 'true' : 'false');
      toggle.textContent = pressed ? 'Masquer' : 'Afficher';
      if (pressed) pwd.focus();
    });

    function scorePassword(value) {
      var score = 0;
      if (!value) return {score:0, props:{}};
      var props = {
        length10: value.length >= 10,
        length12: value.length >= 12,
        lower: /[a-z]/.test(value),
        upper: /[A-Z]/.test(value),
        digit: /[0-9]/.test(value),
        special: /[^A-Za-z0-9]/.test(value)
      };
      if (props.length10) score += 1;
      if (props.length12) score += 1;
      if (props.lower) score += 1;
      if (props.upper) score += 1;
      if (props.digit) score += 1;
      if (props.special) score += 1;
      // normalize to 0..4
      var normalized;
      if (score <= 1) normalized = 0;
      else if (score === 2) normalized = 1;
      else if (score === 3) normalized = 2;
      else if (score === 4) normalized = 3;
      else normalized = 4;
      return { score: normalized, raw: score, props: props };
    }

    function renderScoreData(data) {
      var val = data.score; // 0..4
      var texts = ['Très faible', 'Faible', 'Moyen', 'Bon', 'Fort'];
      var text = texts[val] || '';
      label.textContent = text;

      // color and light segments (red = bad, yellow = medium, green = good)
      var lit = val + 1; // number of segments to light (1..5)
      var stateClass = (val <= 1) ? 'active-bad' : (val === 2 ? 'active-med' : 'active-good');
      segments.forEach(function (seg, idx) {
        // reset
        seg.className = 'segment';
        seg.classList.add('animate-' + idx);
        seg.style.transform = 'scaleY(1)';
        // light up
        if (idx < lit) {
          seg.classList.add(stateClass);
        }
      });
      // trigger reflow to allow staggered transitions
      void barContainer.offsetWidth;

      // hints
      if (hintsWrapper && hintsList) {
        hintsList.innerHTML = '';
        var p = data.props;
        var suggestions = [];
        if (!p.upper) suggestions.push('Ajouter une majuscule');
        if (!p.lower) suggestions.push('Ajouter une minuscule');
        if (!p.digit) suggestions.push('Ajouter un chiffre');
        if (!p.special) suggestions.push('Ajouter un caractère spécial (ex: !@#)');
        if (!p.length12) suggestions.push('Utiliser 12 caractères ou plus');
        if (suggestions.length && val < 4) {
          suggestions.slice(0,3).forEach(function (sug) {
            var li = document.createElement('li'); li.textContent = sug; hintsList.appendChild(li);
          });
          hintsWrapper.style.display = 'block';
        } else {
          hintsWrapper.style.display = 'none';
        }
      }
    }

    pwd.addEventListener('input', function () {
      var val = pwd.value || '';
      var res = scorePassword(val);
      renderScoreData(res);
    });
  }

  /** Champ mot de passe de la popup superviseur. */
  function initSuperviseurPasswordUx() {
    initPasswordUxFor('modal-password', 'password');
  }

  /** Champ mot de passe de la popup compte RH (même UX que superviseur). */
  function initRhPasswordUx() {
    initPasswordUxFor('rh-modal-password', 'rh-password');
  }

  /** Champ mot de passe de la popup vendeur. */
  function initVendeurPasswordUx() {
    initPasswordUxFor('vendeur-modal-password', 'password');
  }

  /**
   * Popup « Voir » du journal des notifications de supervision.
   * Le bouton (icône œil) de chaque ligne porte le détail dans des attributs
   * data-* : on recopie ces valeurs dans le dialogue puis on l'affiche.
   * Les clics sont captés par délégation pour que les boutons continuent de
   * fonctionner après un filtrage ou un changement de page (contenu re-rendu).
   */
  function initNotifDetailModal() {
    var modal = document.getElementById('notif-detail-modal');
    if (!modal) return;

    var closeBtn = document.getElementById('notif-detail-close');
    var okBtn = document.getElementById('notif-detail-ok');
    var lastFocus = null;

    /** Remplit un champ du dialogue (« — » quand la valeur est vide). */
    function setField(id, value) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = (value === null || value === undefined || value === '') ? '—' : value;
    }

    function open(btn) {
      setField('nd-date', btn.getAttribute('data-date'));
      setField('nd-actor', btn.getAttribute('data-actor'));
      setField('nd-action', btn.getAttribute('data-action'));
      setField('nd-entity', btn.getAttribute('data-entity'));
      setField('nd-title', btn.getAttribute('data-title'));
      setField('nd-ip', btn.getAttribute('data-ip'));
      setField('nd-state', btn.getAttribute('data-state'));
      // Le détail reste vide si absent : le CSS affiche alors un tiret.
      var detail = document.getElementById('nd-detail');
      if (detail) detail.textContent = btn.getAttribute('data-detail') || '';
      lastFocus = btn;
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (okBtn) okBtn.focus();
    }

    function close() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocus && document.body.contains(lastFocus)) lastFocus.focus();
    }

    document.addEventListener('click', function (e) {
      var target = e.target;
      if (!target || !target.closest) return;
      var btn = target.closest('[data-notif-view]');
      if (!btn) return;
      e.preventDefault();
      open(btn);
    });

    if (closeBtn) closeBtn.addEventListener('click', close);
    if (okBtn) okBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.style.display === 'flex') close();
    });
  }

  /**
   * Aperçu d'une pièce jointe dans une modale (bouton « Aperçu » 👁).
   *
   * Le type d'affichage est décidé par le serveur (attachment_preview_kind) et
   * transmis via data-attachment-kind :
   *   - image    : <img> (le fichier est servi en inline) ;
   *   - pdf      : <iframe>, qui laisse le lecteur PDF natif du navigateur ;
   *   - audio    : <audio controls> ;
   *   - unsupported (Word, Excel…) : nom du fichier + téléchargement.
   *
   * La modale existe dans dossier_view.php pour les trois rôles (vendeur,
   * superviseur, administrateur) ; ce module se contente de la piloter.
   */
  function initAttachmentPreview() {
    var modal = document.getElementById('attachment-preview-modal');
    if (!modal) return;

    var body = modal.querySelector('[data-attachment-preview-body]');
    var nameEl = modal.querySelector('[data-attachment-preview-name]');
    var typeEl = modal.querySelector('[data-attachment-preview-type]');
    var downloadBtn = modal.querySelector('[data-attachment-preview-download]');
    var lastFocus = null;
    var previousOverflow = '';

    /** Vide la zone d'affichage (libère le lecteur PDF et l'audio). */
    function clearBody() {
      while (body.firstChild) {
        body.removeChild(body.firstChild);
      }
    }

    /** Bloc « fichier non affichable » : icône + nom du fichier + explication. */
    function renderUnsupported(name, typeLabel) {
      var box = document.createElement('div');
      box.className = 'attachment-preview-unavailable';

      var icon = document.createElement('div');
      icon.className = 'attachment-preview-unavailable-icon';
      icon.textContent = '\u{1F4C4}';

      var title = document.createElement('p');
      title.className = 'attachment-preview-unavailable-title';
      title.textContent = 'Aperçu indisponible pour ce format';

      var hint = document.createElement('p');
      hint.className = 'muted';
      hint.textContent = (typeLabel ? typeLabel + ' — ' : '')
        + 'le fichier « ' + name + ' » ne peut pas être affiché directement dans le navigateur.';

      box.appendChild(icon);
      box.appendChild(title);
      box.appendChild(hint);
      return box;
    }

    /** Construit le contenu de la modale selon le type du fichier. */
    function render(btn) {
      var kind = btn.getAttribute('data-attachment-kind') || 'unsupported';
      var name = btn.getAttribute('data-attachment-name') || 'Pièce jointe';
      var typeLabel = btn.getAttribute('data-attachment-type') || '';
      var url = btn.getAttribute('data-attachment-url') || '';
      var downloadUrl = btn.getAttribute('data-attachment-download') || '';
      var canDownload = btn.getAttribute('data-attachment-can-download') !== '0';

      clearBody();

      if (kind === 'image' && url) {
        var img = document.createElement('img');
        img.className = 'attachment-preview-image';
        img.alt = name;
        img.src = url;
        body.appendChild(img);
      } else if (kind === 'pdf' && url) {
        var frame = document.createElement('iframe');
        frame.className = 'attachment-preview-frame';
        frame.setAttribute('title', 'Aperçu de ' + name);
        frame.src = url;
        body.appendChild(frame);
      } else if (kind === 'audio' && url) {
        var player = document.createElement('audio');
        player.className = 'attachment-preview-audio';
        player.setAttribute('controls', 'controls');
        player.setAttribute('controlsList', 'nodownload');
        player.setAttribute('preload', 'metadata');
        player.src = url;
        body.appendChild(player);
      } else {
        body.appendChild(renderUnsupported(name, typeLabel));
      }

      // Le téléchargement n'est proposé qu'aux rôles qui y ont droit
      // (le serveur l'interdit aux superviseurs).
      if (downloadUrl && canDownload) {
        downloadBtn.setAttribute('href', downloadUrl);
        downloadBtn.hidden = false;
      } else {
        downloadBtn.removeAttribute('href');
        downloadBtn.hidden = true;
      }
    }

    function open(btn) {
      lastFocus = btn;
      nameEl.textContent = btn.getAttribute('data-attachment-name') || '';
      typeEl.textContent = btn.getAttribute('data-attachment-type') || '';
      render(btn);
      previousOverflow = document.body.style.overflow;
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      var closeBtn = modal.querySelector('[data-attachment-preview-close]');
      if (closeBtn) closeBtn.focus();
    }

    function close() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = previousOverflow;
      clearBody();
      downloadBtn.removeAttribute('href');
      if (lastFocus && document.body.contains(lastFocus)) lastFocus.focus();
    }

    document.addEventListener('click', function (e) {
      if (!e.target || !e.target.closest) return;
      var btn = e.target.closest('[data-attachment-preview]');
      if (!btn) return;
      e.preventDefault();
      open(btn);
    });

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        close();
        return;
      }
      if (e.target.closest && e.target.closest('[data-attachment-preview-close]')) close();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.style.display === 'flex') close();
    });
  }

})();
