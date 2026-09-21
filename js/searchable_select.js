/**
 * searchable_select.js
 * Enhances all <select> elements across forms with a modern, real-time searchable dropdown.
 * Features:
 * - Instant type-to-filter search
 * - Full keyboard navigation (Arrows, Enter, Escape, Tab)
 * - Auto-flip upwards when near bottom of viewport/modal
 * - Seamless integration with dynamic AJAX options (MutationObserver)
 * - Automatic synchronization on select.value assignment and form reset
 * - HTML5 validation styling (.is-invalid)
 */

(function () {
    'use strict';

    const instances = new WeakMap();

    class SearchableSelect {
        constructor(select) {
            this.select = select;
            if (instances.has(select)) return instances.get(select);
            instances.set(select, this);

            this.isOpen = false;
            this.activeIndex = -1;
            this.init();
        }

        init() {
            // Check if already initialized
            if (this.select.parentElement && this.select.parentElement.classList.contains('searchable-select-wrapper')) {
                return;
            }

            // Create wrapper
            this.wrapper = document.createElement('div');
            this.wrapper.className = 'searchable-select-wrapper';
            if (this.select.id) {
                this.wrapper.setAttribute('data-for-id', this.select.id);
            }

            // Insert wrapper before select and move select inside
            this.select.parentNode.insertBefore(this.wrapper, this.select);
            this.wrapper.appendChild(this.select);

            // Create Trigger Button
            this.trigger = document.createElement('div');
            this.trigger.className = 'searchable-select-trigger form-control';
            this.trigger.setAttribute('tabindex', '0');
            this.trigger.setAttribute('role', 'combobox');
            this.trigger.setAttribute('aria-expanded', 'false');

            this.triggerText = document.createElement('span');
            this.triggerText.className = 'searchable-select-label';

            this.arrow = document.createElement('i');
            this.arrow.className = 'fa-solid fa-chevron-down searchable-select-arrow';

            this.trigger.appendChild(this.triggerText);
            this.trigger.appendChild(this.arrow);
            this.wrapper.appendChild(this.trigger);

            // Create Dropdown Container
            this.dropdown = document.createElement('div');
            this.dropdown.className = 'searchable-select-dropdown';
            this.dropdown.style.display = 'none';

            // Search Box
            this.searchBox = document.createElement('div');
            this.searchBox.className = 'searchable-select-search';

            this.searchIcon = document.createElement('i');
            this.searchIcon.className = 'fa-solid fa-magnifying-glass searchable-select-search-icon';

            this.searchInput = document.createElement('input');
            this.searchInput.type = 'text';
            this.searchInput.className = 'searchable-select-search-input';
            this.searchInput.placeholder = 'Type to search...';
            this.searchInput.setAttribute('autocomplete', 'off');

            this.clearBtn = document.createElement('button');
            this.clearBtn.type = 'button';
            this.clearBtn.className = 'searchable-select-search-clear';
            this.clearBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            this.clearBtn.style.display = 'none';

            this.searchBox.appendChild(this.searchIcon);
            this.searchBox.appendChild(this.searchInput);
            this.searchBox.appendChild(this.clearBtn);
            this.dropdown.appendChild(this.searchBox);

            // Options List
            this.optionsList = document.createElement('ul');
            this.optionsList.className = 'searchable-select-options';
            this.dropdown.appendChild(this.optionsList);

            // No Results Message
            this.emptyMsg = document.createElement('div');
            this.emptyMsg.className = 'searchable-select-empty';
            this.emptyMsg.textContent = 'No matching options found';
            this.emptyMsg.style.display = 'none';
            this.dropdown.appendChild(this.emptyMsg);

            this.wrapper.appendChild(this.dropdown);

            // Prevent horizontal scrolling inside the dropdown
            this.dropdown.addEventListener('scroll', () => {
                if (this.dropdown.scrollLeft !== 0) {
                    this.dropdown.scrollLeft = 0;
                }
            });

            // Hide original select securely while keeping it accessible to forms
            this.select.classList.add('searchable-select-hidden');

            // Build options and update trigger
            this.buildOptions();
            this.updateTrigger();

            // Initial hidden / disabled state check
            if (this.select.style.display === 'none' || this.select.hidden) {
                this.wrapper.style.display = 'none';
            }
            if (this.select.disabled) {
                this.trigger.classList.add('is-disabled');
                this.trigger.setAttribute('tabindex', '-1');
            }

            // Bind events
            this.bindEvents();

            // Observe dynamic option changes (e.g. AJAX dropdown population)
            this.observer = new MutationObserver(() => {
                this.buildOptions();
                this.updateTrigger();
            });
            this.observer.observe(this.select, { childList: true, subtree: true });

            // Observe select style/hidden/disabled mutations
            this.attrObserver = new MutationObserver(() => {
                const isHidden = (this.select.style.display === 'none' || this.select.hidden);
                this.wrapper.style.display = isHidden ? 'none' : '';
                if (this.select.disabled) {
                    this.trigger.classList.add('is-disabled');
                    this.trigger.setAttribute('tabindex', '-1');
                    this.close();
                } else {
                    this.trigger.classList.remove('is-disabled');
                    this.trigger.setAttribute('tabindex', '0');
                }
            });
            this.attrObserver.observe(this.select, { attributes: true, attributeFilter: ['style', 'hidden', 'disabled'] });

            // Hook programmatic select.value assignment
            this.hookValueSetter();
        }

        hookValueSetter() {
            const self = this;
            const originalDescriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
            if (originalDescriptor && !this.select._hasSearchableHook) {
                this.select._hasSearchableHook = true;
                Object.defineProperty(this.select, 'value', {
                    get() {
                        return originalDescriptor.get.call(this);
                    },
                    set(val) {
                        originalDescriptor.set.call(this, val);
                        self.updateTrigger();
                    },
                    configurable: true
                });
            }
        }

        buildOptions() {
            this.optionsList.innerHTML = '';
            const options = Array.from(this.select.options);

            options.forEach((opt, index) => {
                const li = document.createElement('li');
                li.className = 'searchable-select-option';
                li.setAttribute('data-value', opt.value);
                li.setAttribute('data-index', index);
                li.textContent = opt.textContent;
                li.title = opt.textContent;

                if (opt.disabled) {
                    li.classList.add('is-disabled');
                }

                if (opt.selected) {
                    li.classList.add('is-selected');
                }

                li.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (opt.disabled) return;
                    this.selectOption(opt.value);
                });

                this.optionsList.appendChild(li);
            });

            this.filterOptions(this.searchInput.value);
        }

        updateTrigger() {
            const selectedOpt = this.select.options[this.select.selectedIndex];
            if (selectedOpt && selectedOpt.textContent.trim() !== '') {
                this.triggerText.textContent = selectedOpt.textContent;
                this.trigger.classList.remove('is-placeholder');
            } else {
                const placeholder = this.select.getAttribute('data-placeholder') || '-- Select an option --';
                this.triggerText.textContent = placeholder;
                this.trigger.classList.add('is-placeholder');
            }

            // Sync 'is-selected' on list items
            const items = this.optionsList.querySelectorAll('.searchable-select-option');
            items.forEach(item => {
                if (item.getAttribute('data-value') === this.select.value) {
                    item.classList.add('is-selected');
                } else {
                    item.classList.remove('is-selected');
                }
            });
        }

        selectOption(value) {
            if (this.select.value !== value) {
                this.select.value = value;
                this.select.dispatchEvent(new Event('change', { bubbles: true }));
                this.select.dispatchEvent(new Event('input', { bubbles: true }));
            }
            this.trigger.classList.remove('is-invalid');
            this.updateTrigger();
            this.close();
            this.trigger.focus();
        }

        open() {
            if (this.isOpen || this.select.disabled || this.wrapper.style.display === 'none' || this.select.hidden) return;

            // Close any other open dropdowns
            document.querySelectorAll('.searchable-select-dropdown').forEach(d => {
                if (d !== this.dropdown) d.style.display = 'none';
            });
            document.querySelectorAll('.searchable-select-trigger').forEach(t => {
                t.setAttribute('aria-expanded', 'false');
                t.classList.remove('is-active');
            });

            // Position & flip check
            this.dropdown.style.display = 'flex';
            this.trigger.setAttribute('aria-expanded', 'true');
            this.trigger.classList.add('is-active');
            this.isOpen = true;

            // Reset scroll positions to prevent any cut-off letters or horizontal shifting
            this.dropdown.scrollLeft = 0;
            this.optionsList.scrollLeft = 0;

            const rect = this.wrapper.getBoundingClientRect();
            const dropdownHeight = 260;
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow < dropdownHeight && spaceAbove > spaceBelow) {
                this.dropdown.classList.add('is-upwards');
            } else {
                this.dropdown.classList.remove('is-upwards');
            }

            // Reset search input & filter
            this.searchInput.value = '';
            this.clearBtn.style.display = 'none';
            this.filterOptions('');

            // Scroll selected option into view strictly vertically within optionsList
            const selectedItem = this.optionsList.querySelector('.searchable-select-option.is-selected');
            if (selectedItem) {
                this.scrollItemIntoView(selectedItem);
            }

            // Focus search input with preventScroll to prevent ancestor horizontal offset
            setTimeout(() => {
                this.dropdown.scrollLeft = 0;
                this.optionsList.scrollLeft = 0;
                if (typeof this.searchInput.focus === 'function') {
                    this.searchInput.focus({ preventScroll: true });
                }
            }, 30);
        }

        close() {
            if (!this.isOpen) return;
            this.dropdown.style.display = 'none';
            this.trigger.setAttribute('aria-expanded', 'false');
            this.trigger.classList.remove('is-active');
            this.isOpen = false;
            this.activeIndex = -1;
        }

        toggle() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        }

        filterOptions(query) {
            const q = query.trim().toLowerCase();
            const items = Array.from(this.optionsList.querySelectorAll('.searchable-select-option'));
            let visibleCount = 0;

            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                const matches = q === '' || text.includes(q);
                if (matches) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            this.emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
            this.clearBtn.style.display = q.length > 0 ? 'inline-flex' : 'none';
            this.activeIndex = -1;
        }

        bindEvents() {
            // Click trigger to toggle
            this.trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggle();
            });

            // Trigger keyboard open
            this.trigger.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.open();
                }
            });

            // Filter typing
            this.searchInput.addEventListener('input', (e) => {
                this.filterOptions(e.target.value);
            });

            // Search input keyboard shortcuts
            this.searchInput.addEventListener('keydown', (e) => {
                const visibleItems = Array.from(this.optionsList.querySelectorAll('.searchable-select-option:not([style*="display: none"]):not(.is-disabled)'));

                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.close();
                    this.trigger.focus();
                } else if (e.key === 'Tab') {
                    this.close();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (visibleItems.length === 0) return;
                    this.activeIndex = (this.activeIndex + 1) % visibleItems.length;
                    this.highlightActive(visibleItems);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (visibleItems.length === 0) return;
                    this.activeIndex = (this.activeIndex - 1 + visibleItems.length) % visibleItems.length;
                    this.highlightActive(visibleItems);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (this.activeIndex >= 0 && visibleItems[this.activeIndex]) {
                        const val = visibleItems[this.activeIndex].getAttribute('data-value');
                        this.selectOption(val);
                    } else if (visibleItems.length === 1) {
                        const val = visibleItems[0].getAttribute('data-value');
                        this.selectOption(val);
                    }
                }
            });

            // Clear search button
            this.clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.searchInput.value = '';
                this.filterOptions('');
                this.searchInput.focus();
            });

            // Close on click outside
            document.addEventListener('click', (e) => {
                if (!this.wrapper.contains(e.target)) {
                    this.close();
                }
            });

            // Form reset synchronization
            if (this.select.form) {
                this.select.form.addEventListener('reset', () => {
                    setTimeout(() => this.updateTrigger(), 10);
                });
            }

            // HTML5 validation styling
            this.select.addEventListener('invalid', () => {
                this.trigger.classList.add('is-invalid');
            });
            this.select.addEventListener('change', () => {
                this.trigger.classList.remove('is-invalid');
                this.updateTrigger();
            });
        }

        scrollItemIntoView(item) {
            if (!item || !this.optionsList) return;
            const itemTop = item.offsetTop;
            const itemBottom = itemTop + item.offsetHeight;
            const cTop = this.optionsList.scrollTop;
            const cBottom = cTop + this.optionsList.clientHeight;

            if (itemTop < cTop) {
                this.optionsList.scrollTop = itemTop;
            } else if (itemBottom > cBottom) {
                this.optionsList.scrollTop = itemBottom - this.optionsList.clientHeight;
            }
        }

        highlightActive(visibleItems) {
            visibleItems.forEach((item, i) => {
                if (i === this.activeIndex) {
                    item.classList.add('is-highlighted');
                    this.scrollItemIntoView(item);
                } else {
                    item.classList.remove('is-highlighted');
                }
            });
        }
    }

    // Initialize all matching select elements
    function initSearchableSelects(root = document) {
        const selector = 'select.form-control, select.form-select, .form-group select, .form-grid select, .modal-content select';
        const selects = root.querySelectorAll(selector);

        selects.forEach(sel => {
            if (sel.classList.contains('no-search') || sel.name.includes('_length') || sel.multiple) {
                return;
            }
            new SearchableSelect(sel);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initSearchableSelects());
    } else {
        initSearchableSelects();
    }

    window.SearchableSelect = SearchableSelect;
    window.initSearchableSelects = initSearchableSelects;

    // Observe body for dynamically inserted forms or modals
    const bodyObserver = new MutationObserver((mutations) => {
        for (const mut of mutations) {
            if (mut.addedNodes && mut.addedNodes.length > 0) {
                mut.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.matches && (node.matches('select') || node.querySelector('select'))) {
                            initSearchableSelects(node);
                        }
                    }
                });
            }
        }
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });

})();
