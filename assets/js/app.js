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
    var editButton = document.querySelector('[data-vendeur-edit]');
    var deleteButton = document.querySelector('[data-vendeur-delete]');
    var deleteForm = document.querySelector('[data-vendeur-delete-form]');
    if (!selectAll || !checkboxes.length || !count) return;

    function selected() { return checkboxes.filter(function (checkbox) { return checkbox.checked; }); }
    function refresh() {
      var total = selected().length;
      count.textContent = total + (total === 1 ? ' vendeur sélectionné' : ' vendeurs sélectionnés');
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
    if (editButton) editButton.addEventListener('click', function () {
      var items = selected();
      if (items.length === 1) showConfirm('Ouvrir le vendeur sélectionné pour le modifier ?', function () {
        window.location.href = 'vendeurs.php?edit=' + encodeURIComponent(items[0].value);
      });
    });
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
