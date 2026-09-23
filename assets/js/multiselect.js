'use strict';

/**
 * MultiSelectDropdown — custom multi-select with checkbox list.
 * Replaces native <select multiple> with an accessible dropdown
 * featuring checkboxes, search, keyboard navigation, and tag display.
 *
 * Usage:
 *   new MultiSelectDropdown(element, options);
 *
 * Options:
 *   element:      HTMLElement container with data-multiselect attribute
 *   options:      Array of { value, label } objects
 *   selected:     Array of initially selected values
 *   name:         Name for the hidden native select (default: from data attribute or 'etat_contrat[]')
 *   placeholder:  Placeholder text for trigger button
 *   searchable:   Show search input (default: true)
 *   maxHeight:    Max height in px for options panel (default: 200)
 *   noResults:    Text shown when search yields no results
 *   selectAll:    Show "Select all" option (default: true when > 5 options)
 *   onchange:     Callback fired when selection changes, receives array of selected values
 */

function MultiSelectDropdown(element, config) {
  this.element = element;
  this.config = Object.assign({
    options: [],
    selected: [],
    name: element.getAttribute('data-name') || 'etat_contrat[]',
    placeholder: 'Sélectionner...',
    searchable: true,
    maxHeight: 200,
    noResults: 'Aucun résultat',
    selectAll: true,
    onchange: null
  }, config || {});

  this.selected = this.config.selected.slice();
  this.isOpen = false;
  this.searchTerm = '';
  this.focusedIndex = -1;
  this.filteredOptions = this.config.options.slice();
  // Mémorise si le dernier clic provenait de l'intérieur du composant
  // (voir _bindEvents : la cible peut être détachée du DOM par le re-rendu).
  this._clickedInside = false;

  this._build();
  this._bindEvents();
  this._render();
}

MultiSelectDropdown.prototype._build = function () {
  var self = this;

  this.element.classList.add('multiselect-dropdown');
  this.element.setAttribute('data-multiselect', '');

  var trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'multiselect-trigger';
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');
  trigger.setAttribute('aria-controls', 'ms-panel-' + this._uniqueId());
  trigger.innerHTML = '<span class="multiselect-label"></span><span class="multiselect-arrow" aria-hidden="true">&#9660;</span>';
  this.element.appendChild(trigger);
  this.trigger = trigger;

  var panel = document.createElement('div');
  panel.className = 'multiselect-panel';
  panel.setAttribute('role', 'listbox');
  panel.setAttribute('aria-multiselectable', 'true');
  panel.hidden = true;
  this.panel = panel;

  var panelId = 'ms-panel-' + this._getId();
  panel.id = panelId;
  trigger.setAttribute('aria-controls', panelId);

  if (this.config.searchable) {
    var searchWrap = document.createElement('div');
    searchWrap.className = 'multiselect-search';
    var searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'multiselect-search-input';
    searchInput.setAttribute('aria-label', 'Rechercher');
    searchInput.placeholder = 'Rechercher...';
    searchInput.autocomplete = 'off';
    searchWrap.appendChild(searchInput);
    panel.appendChild(searchWrap);
    this.searchInput = searchInput;
  }

  var optionsWrap = document.createElement('div');
  optionsWrap.className = 'multiselect-options';
  panel.appendChild(optionsWrap);
  this.optionsWrap = optionsWrap;

  this.element.appendChild(panel);

  var select = document.createElement('select');
  select.name = this.config.name;
  select.multiple = true;
  select.hidden = true;
  this.hiddenSelect = select;
  this.element.appendChild(select);
};

MultiSelectDropdown.prototype._getId = function () {
  if (!this._id) {
    this._id = 'ms-' + Math.random().toString(36).substr(2, 9);
  }
  return this._id;
};

MultiSelectDropdown.prototype._uniqueId = function () {
  return this._getId();
};

MultiSelectDropdown.prototype._bindEvents = function () {
  var self = this;

  this.trigger.addEventListener('click', function (e) {
    e.stopPropagation();
    self.toggle();
  });

  this.trigger.addEventListener('keydown', function (e) {
    self._handleTriggerKey(e);
  });

  if (this.searchInput) {
    this.searchInput.addEventListener('input', function () {
      self.searchTerm = self.searchInput.value.toLowerCase();
      self._filterOptions();
      self.focusedIndex = -1;
    });
    this.searchInput.addEventListener('keydown', function (e) {
      self._handleSearchKey(e);
    });
  }

  // Origine du clic, relevée pendant la phase de CAPTURE : à ce moment la
  // cible est encore dans la liste. Après un clic dans une option, le re-rendu
  // recrée les lignes et détache l'ancienne cible : sans cette mémorisation,
  // « element.contains(e.target) » serait faux et la liste se fermait à chaque
  // sélection. Elle ne doit se fermer que sur un clic à l'extérieur (ou Échap).
  document.addEventListener('click', function (e) {
    self._clickedInside = self.element.contains(e.target);
  }, true);

  document.addEventListener('click', function (e) {
    if (self.isOpen && !self._clickedInside) {
      self.close();
    }
  });
};

MultiSelectDropdown.prototype._handleTriggerKey = function (e) {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (!this.isOpen) {
      this.open();
    }
    this._focusOption(0);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (!this.isOpen) {
      this.open();
    }
    this._focusOption(this.filteredOptions.length - 1);
  } else if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  } else if (e.key === 'Escape') {
    if (this.isOpen) {
      this.close();
      this.trigger.focus();
    }
  }
};

MultiSelectDropdown.prototype._handleSearchKey = function (e) {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    this._focusOption(0);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (this.focusedIndex <= 0) {
      this.searchInput.focus();
      this.focusedIndex = -1;
    } else {
      this._focusOption(this.focusedIndex - 1);
    }
  } else if (e.key === 'Escape') {
    this.close();
    this.trigger.focus();
  } else if (e.key === 'Enter') {
    if (this.focusedIndex >= 0 && this.focusedIndex < this.filteredOptions.length) {
      this._toggleOption(this.focusedIndex);
    }
  }
};

MultiSelectDropdown.prototype._focusOption = function (index) {
  var items = this.optionsWrap.querySelectorAll('.multiselect-option');
  items.forEach(function (el) {
    el.removeAttribute('aria-selected');
    el.classList.remove('is-focused');
  });
  if (index >= 0 && index < items.length) {
    this.focusedIndex = index;
    items[index].classList.add('is-focused');
    items[index].setAttribute('aria-selected', items[index].dataset.selected === 'true' ? 'true' : 'false');
    items[index].scrollIntoView({ block: 'nearest' });
    items[index].focus();
  }
};

MultiSelectDropdown.prototype._toggleOption = function (index) {
  var opt = this.filteredOptions[index];
  if (!opt) return;
  var value = opt.value;
  var pos = this.selected.indexOf(value);
  if (pos >= 0) {
    this.selected.splice(pos, 1);
  } else {
    this.selected.push(value);
  }
  this._render();
  this._syncHiddenSelect();
  if (this.config.onchange) {
    this.config.onchange(this.selected.slice());
  }
};

MultiSelectDropdown.prototype._filterOptions = function () {
  var self = this;
  if (!this.searchTerm) {
    this.filteredOptions = this.config.options.slice();
  } else {
    this.filteredOptions = this.config.options.filter(function (opt) {
      return opt.label.toLowerCase().indexOf(self.searchTerm) !== -1;
    });
  }
  this._renderOptions();
};

MultiSelectDropdown.prototype._buildSelectAll = function () {
  if (!this.config.selectAll || this.config.options.length <= 5) return null;
  var total = this.config.options.length;
  var selectedCount = this.config.options.filter(function (opt) {
    return this.selected.indexOf(opt.value) !== -1;
  }.bind(this)).length;
  var allSelected = total > 0 && selectedCount === total;
  return {
    value: '__ALL__',
    // Le libellé bascule selon l'état : tout est coché => on propose de tout décocher.
    label: allSelected ? 'Tout désélectionner' : 'Tout sélectionner',
    type: 'all',
    selected: allSelected,
    checked: allSelected,
    // Sélection partielle : case « Tout sélectionner » en état indéterminé (tiret).
    indeterminate: selectedCount > 0 && !allSelected
  };
};

MultiSelectDropdown.prototype._render = function () {
  this._renderTrigger();
  this._renderOptions(true);
  this._syncHiddenSelect();
};

MultiSelectDropdown.prototype._renderTrigger = function () {
  var label = this.trigger.querySelector('.multiselect-label');
  if (this.selected.length === 0) {
    label.textContent = this.config.placeholder;
    this.element.classList.remove('has-selection');
  } else if (this.selected.length === 1) {
    var opt = this.config.options.find(function (o) { return o.value === this.selected[0]; }.bind(this));
    label.textContent = opt ? opt.label : this.selected[0];
    this.element.classList.add('has-selection');
  } else {
    label.textContent = this.selected.length + ' sélectionné(s)';
    this.element.classList.add('has-selection');
  }
};

/**
 * Reconstruit la liste des options.
 * keepScroll = true : conserve la position de défilement (cas d'une sélection :
 * l'utilisateur peut cocher plusieurs valeurs à la suite sans remonter en haut
 * de la liste). Dans les autres cas (recherche), la liste repart du haut.
 */
MultiSelectDropdown.prototype._renderOptions = function (keepScroll) {
  var self = this;
  var previousScroll = keepScroll === true ? this.optionsWrap.scrollTop : 0;

  this.optionsWrap.innerHTML = '';
  this.focusedIndex = -1;

  var options = this.filteredOptions;
  var selectAllOpt = this._buildSelectAll();
  if (selectAllOpt) {
    var allItem = this._createOptionElement(selectAllOpt, true);
    this.optionsWrap.appendChild(allItem);
  }

  if (options.length === 0) {
    var empty = document.createElement('div');
    empty.className = 'multiselect-empty';
    empty.textContent = this.config.noResults;
    empty.setAttribute('role', 'presentation');
    this.optionsWrap.appendChild(empty);
    return;
  }

  options.forEach(function (opt, i) {
    var isSelected = self.selected.indexOf(opt.value) !== -1;
    var item = self._createOptionElement(Object.assign({}, opt, { selected: isSelected }), false);
    self.optionsWrap.appendChild(item);
  });

  // Réapplique explicitement la position de défilement : conservée après une
  // sélection, remise à zéro après une recherche (la liste repart du haut).
  this.optionsWrap.scrollTop = previousScroll;
};

MultiSelectDropdown.prototype._createOptionElement = function (opt, isSelectAll) {
  var self = this;
  var item = document.createElement('div');
  item.className = 'multiselect-option';
  item.setAttribute('role', 'option');
  item.setAttribute('data-value', opt.value);
  item.setAttribute('data-selected', opt.selected ? 'true' : 'false');
  item.setAttribute('tabindex', '-1');

  if (opt.selected) {
    item.setAttribute('aria-selected', 'true');
    item.classList.add('is-selected');
  } else {
    item.setAttribute('aria-selected', 'false');
  }

  if (isSelectAll) {
    item.classList.add('is-select-all');
  }

  var checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.checked = !!opt.selected;
  if (opt.indeterminate) {
    // Case « Tout sélectionner » : sélection partielle => état indéterminé (tiret).
    checkbox.indeterminate = true;
  }
  checkbox.setAttribute('aria-hidden', 'true');
  checkbox.tabIndex = -1;
  checkbox.addEventListener('change', function (e) {
    e.stopPropagation();
    if (opt.type === 'all') {
      self._handleSelectAll(checkbox.checked);
    } else {
      var pos = self.selected.indexOf(opt.value);
      if (checkbox.checked && pos < 0) {
        self.selected.push(opt.value);
      } else if (!checkbox.checked && pos >= 0) {
        self.selected.splice(pos, 1);
      }
      self._render();
      if (self.config.onchange) {
        self.config.onchange(self.selected.slice());
      }
    }
  });
  item.appendChild(checkbox);

  var label = document.createElement('span');
  label.className = 'multiselect-option-label';
  label.textContent = opt.label;
  item.appendChild(label);

  item.addEventListener('click', function (e) {
    // Un clic direct sur la case à cocher est déjà traité par l'événement
    // 'change' natif : le rejouer ici inverserait la case une seconde fois
    // (cocher/décocher une case ne produisait donc aucun effet).
    if (e.target === checkbox) return;
    if (opt.type === 'all') {
      self._handleSelectAll(!checkbox.checked);
    } else {
      checkbox.checked = !checkbox.checked;
      checkbox.dispatchEvent(new Event('change'));
    }
  });

  item.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      if (opt.type === 'all') {
        self._handleSelectAll(!checkbox.checked);
      } else {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
      }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      var next = self.optionsWrap.querySelectorAll('.multiselect-option');
      var idx = Array.prototype.indexOf.call(next, item);
      self._focusOption(idx + 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      var prev = self.optionsWrap.querySelectorAll('.multiselect-option');
      var pidx = Array.prototype.indexOf.call(prev, item);
      if (pidx > 0) {
        self._focusOption(pidx - 1);
      } else {
        if (self.searchInput) {
          self.searchInput.focus();
        }
        self.focusedIndex = -1;
      }
    }
  });

  return item;
};

MultiSelectDropdown.prototype._handleSelectAll = function (checked) {
  var self = this;
  if (checked) {
    this.config.options.forEach(function (opt) {
      if (self.selected.indexOf(opt.value) === -1) {
        self.selected.push(opt.value);
      }
    });
  } else {
    this.selected = [];
  }
  this._render();
  if (this.config.onchange) {
    this.config.onchange(this.selected.slice());
  }
};

MultiSelectDropdown.prototype._syncHiddenSelect = function () {
  this.hiddenSelect.innerHTML = '';
  this.selected.forEach(function (val) {
    var option = document.createElement('option');
    option.value = val;
    option.selected = true;
    this.hiddenSelect.appendChild(option);
  }.bind(this));
};

MultiSelectDropdown.prototype.open = function () {
  this.isOpen = true;
  this.panel.hidden = false;
  this.trigger.setAttribute('aria-expanded', 'true');
  this.element.classList.add('is-open');
  this._filterOptions();
  if (this.searchInput) {
    this.searchInput.value = '';
    this.searchTerm = '';
    this.searchInput.focus();
  }
};

MultiSelectDropdown.prototype.close = function () {
  this.isOpen = false;
  this.panel.hidden = true;
  this.trigger.setAttribute('aria-expanded', 'false');
  this.element.classList.remove('is-open');
  this.focusedIndex = -1;
};

MultiSelectDropdown.prototype.toggle = function () {
  if (this.isOpen) {
    this.close();
  } else {
    this.open();
  }
};

MultiSelectDropdown.prototype.getValue = function () {
  return this.selected.slice();
};

MultiSelectDropdown.prototype.setValue = function (values) {
  this.selected = values.slice();
  this._render();
};

MultiSelectDropdown.prototype.destroy = function () {
  this.element.innerHTML = '';
};
