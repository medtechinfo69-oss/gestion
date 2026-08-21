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
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    });
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
