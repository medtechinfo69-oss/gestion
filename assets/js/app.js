/**
 * Gestion des Dossiers — comportements front-end.
 * Aucune dépendance externe (JavaScript natif uniquement).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initSidebarToggle();
    initCaAutoCalc();
    initConfirmActions();
    initFlashAutoHide();
    initNewVendeurToggle();
    initBulkSelection();
    initVendeurSelection();
    initOrigineSelection();
    initOrigineEditModal();
    initVendeurEditModal();
    initSuperviseurModal();
    initSuperviseurPasswordUx();
    initTrashSelection();
    initSecurityMode();
  });

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
      var coefficient = origineInput.value === 'FID+2ans' ? 0.846 * 0.5 : 0.846;
      annuelInput.value = (Math.round(monthlyAmount * 12 * coefficient * 100) / 100).toFixed(2);
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
        showConfirm(msg, function () {
          if (el.tagName === 'FORM') el.submit();
          else if (el.form) el.form.submit();
          else window.location.href = el.href;
        });
      });
    });
  }

  function showConfirm(message, onConfirm) {
    var overlay = document.createElement('div');
    overlay.className = 'confirm-modal';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = '<div class="confirm-dialog">' +
      '<h2>Confirmer l’action</h2>' +
      '<p>' + escapeHtml(message) + '</p>' +
      '<div class="confirm-actions"><button type="button" class="btn btn-outline" data-confirm-cancel>Annuler</button>' +
      '<button type="button" class="btn btn-danger" data-confirm-submit>Confirmer</button></div>' +
      '</div>';
    document.body.appendChild(overlay);
    var cancel = overlay.querySelector('[data-confirm-cancel]');
    var submit = overlay.querySelector('[data-confirm-submit]');
    function close() { overlay.remove(); document.removeEventListener('keydown', onKeydown); }
    function onKeydown(e) {
      if (e.key === 'Escape') close();
      if (e.key === 'Enter') submit.click();
    }
    cancel.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    submit.addEventListener('click', function () { close(); onConfirm(); });
    document.addEventListener('keydown', onKeydown);
    submit.focus();
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

    function refresh() {
      var selectedItems = selected();
      var total = selectedItems.length;
      count.textContent = total + (total === 1 ? ' dossier sélectionné' : ' dossiers sélectionnés');
      selectAll.checked = total === checkboxes.length;
      selectAll.indeterminate = total > 0 && total < checkboxes.length;
      if (editButton) editButton.disabled = total !== 1;
      if (deleteButton) deleteButton.disabled = total === 0;
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

  function openVendorEditModal(id, nom, email) {
    var modal = document.getElementById('vendeur-edit-modal');
    var idInput = document.getElementById('vendeur-edit-id');
    var nameInput = document.getElementById('vendeur-edit-name');
    var emailInput = document.getElementById('vendeur-edit-email');
    if (!modal || !idInput || !nameInput || !emailInput) return;

    idInput.value = String(id || '');
    nameInput.value = String(nom || '');
    emailInput.value = String(email || '');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () {
      nameInput.focus();
      nameInput.select();
    }, 50);
  }

  function initVendeurEditModal() {
    var modal = document.getElementById('vendeur-edit-modal');
    var idInput = document.getElementById('vendeur-edit-id');
    var nameInput = document.getElementById('vendeur-edit-name');
    var emailInput = document.getElementById('vendeur-edit-email');
    if (!modal || !idInput || !nameInput || !emailInput) return;

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

      var editButton = target.closest('[data-vendeur-edit]');
      if (editButton) {
        event.preventDefault();
        openVendorEditModal(
          editButton.getAttribute('data-id') || '',
          editButton.getAttribute('data-nom') || '',
          editButton.getAttribute('data-email') || ''
        );
        return;
      }

      if (target.closest('[data-vendeur-close]')) {
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

  /** Initialise la popup de création/modification d'un superviseur. */
  function initSuperviseurModal() {
    var modal = document.getElementById('superviseur-modal');
    var form = document.getElementById('superviseur-form');
    var modalTitle = document.getElementById('modal-title');
    var idInput = document.getElementById('modal-id');
    var passwordIdInput = document.getElementById('password-id');
    var nomInput = document.getElementById('modal-nom');
    var usernameInput = document.getElementById('modal-username');
    var emailInput = document.getElementById('modal-email');
    var activeInput = document.getElementById('modal-active');
    var passwordInput = document.getElementById('modal-password');
    var submitButton = document.getElementById('modal-submit');
    var btnNewSuperviseur = document.getElementById('btn-new-superviseur');
    if (!modal || !form || !idInput || !nomInput || !usernameInput) return;

    function openModal(id, nom, username, email, isActive) {
      var isEdit = !!id;
      idInput.value = String(id || '');
      passwordIdInput.value = String(id || '');
      nomInput.value = String(nom || '');
      usernameInput.value = String(username || '');
      emailInput.value = String(email || '');
      activeInput.value = isActive ? '1' : '0';
      if (passwordInput) passwordInput.value = '';
      if (passwordInput) {
        passwordInput.type = 'password';
        passwordInput.required = !isEdit;
        var toggle = document.getElementById('password-toggle');
        if (toggle) {
          toggle.textContent = 'Afficher';
          toggle.setAttribute('aria-pressed', 'false');
        }
      }

      var passwordHelp = document.getElementById('password-help');
      if (passwordHelp) {
        passwordHelp.textContent = isEdit
          ? '10 caractères minimum, avec au moins une majuscule, une minuscule et un chiffre. Laisser vide pour conserver le mot de passe actuel.'
          : '10 caractères minimum, avec au moins une majuscule, une minuscule et un chiffre.';
      }

      if (modalTitle) {
        modalTitle.textContent = isEdit ? 'Modifier le superviseur' : 'Nouveau superviseur';
      }
      if (submitButton) {
        submitButton.textContent = isEdit ? 'Enregistrer' : 'Créer';
      }
      if (form) {
        form.action = isEdit ? (form.getAttribute('data-edit-action') || '/actions/superviseur_update.php') : (form.getAttribute('data-create-action') || '/actions/superviseur_save.php');
      }
      if (passwordInput) {
        var group = passwordInput.closest('.form-group');
        if (group) group.style.display = '';
      }
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

    if (btnNewSuperviseur) {
      btnNewSuperviseur.addEventListener('click', function () {
        openModal('', '', '', '', true);
      });
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;

      var editButton = target.closest('[data-superviseur-edit]');
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

      if (target.closest('#modal-cancel') || target.closest('#password-cancel')) {
        closeModal();
        return;
      }

      if (target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });
  }

  /** Améliore l'UX du champ mot de passe : bascule afficher/masquer et indicateur de robustesse. */
  function initSuperviseurPasswordUx() {
    var pwd = document.getElementById('modal-password');
    var toggle = document.getElementById('password-toggle');
    if (!pwd || !toggle) return;

    var strength = document.getElementById('password-strength');
    if (!strength) {
      strength = document.createElement('div');
      strength.id = 'password-strength';
      strength.className = 'password-strength';
      strength.setAttribute('aria-live', 'polite');
      pwd.parentNode.parentNode.appendChild(strength);
    }

    var barContainer = strength.querySelector('.password-strength-bar');
    var label = strength.querySelector('.password-strength-label');
    var hintsWrapper = document.getElementById('password-strength-hints');
    var hintsList = document.getElementById('password-hints-list');
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
      hintsWrapper.id = 'password-strength-hints';
      hintsWrapper.className = 'password-strength-hints';
      hintsWrapper.style.display = 'none';
      hintsList = document.createElement('ul');
      hintsList.id = 'password-hints-list';
      hintsWrapper.appendChild(hintsList);
      strength.parentNode.appendChild(hintsWrapper);
    } else {
      hintsList = document.getElementById('password-hints-list');
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

  /** Bloque les actions de copie, menu contextuel et impression en mode sécurisé. */
  function initSecurityMode() {
    if (!document.body.classList.contains('security-mode')) return;

    document.addEventListener('contextmenu', function (e) {
      e.preventDefault();
    });

    document.addEventListener('dragstart', function (e) {
      e.preventDefault();
    });

    document.addEventListener('keydown', function (e) {
      var key = String(e.key).toLowerCase();
      var modifier = e.ctrlKey || e.metaKey;
      if (key === 'printscreen' || (modifier && ['p', 's', 'u'].indexOf(key) !== -1)) {
        e.preventDefault();
        document.body.classList.add('security-alert');
        setTimeout(function () {
          document.body.classList.remove('security-alert');
        }, 800);
      }
    });
  }

})();
