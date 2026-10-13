/**
 * Global Theme Manager & Dribbble UI Interaction Engine
 * - Day & Night Mode Toggle (Persistent)
 * - Floating Purple Theme Switcher (Bottom Right)
 * - Universal Command Palette (Ctrl+K / ⌘K)
 * - Sidebar Tooltip Initializer
 */

(function () {
    // Determine initial theme
    const savedTheme = localStorage.getItem('estate_app_theme');
    const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const initialTheme = savedTheme || (systemPrefersDark ? 'dark' : 'light');

    // Apply theme immediately to prevent white flash
    document.documentElement.setAttribute('data-theme', initialTheme);

    window.addEventListener('DOMContentLoaded', () => {
        applyTheme(initialTheme);
        bindThemeToggleButtons();
        injectFloatingThemeButton();
        initSidebarTooltips();
        initCommandPalette();
    });

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        if (theme === 'dark') {
            document.body.classList.add('dark-mode');
        } else {
            document.body.classList.remove('dark-mode');
        }
        localStorage.setItem('estate_app_theme', theme);
        updateToggleIcons(theme);
        window.dispatchEvent(new CustomEvent('estateThemeChanged', { detail: { theme } }));
    }

    function updateToggleIcons(theme) {
        const toggleBtns = document.querySelectorAll('.theme-toggle-btn, .floating-theme-switch');
        toggleBtns.forEach(btn => {
            const icon = btn.querySelector('i');
            const textSpan = btn.querySelector('.theme-text');
            if (theme === 'dark') {
                if (icon) icon.className = 'fa-solid fa-sun';
                if (textSpan) textSpan.textContent = 'Light Mode';
                btn.setAttribute('title', 'Switch to Light Mode');
            } else {
                if (icon) icon.className = 'fa-solid fa-moon';
                if (textSpan) textSpan.textContent = 'Dark Mode';
                btn.setAttribute('title', 'Switch to Dark Mode');
            }
        });

        const themeDesc = document.querySelector('.mobile-drawer-theme-desc');
        if (themeDesc) {
            themeDesc.textContent = (theme === 'dark') ? 'Night mode active (OLED calm)' : 'Day mode active (Clear daylight)';
        }
    }

    function bindThemeToggleButtons() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.theme-toggle-btn, .floating-theme-switch');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                applyTheme(newTheme);
            }
        });
    }

    // Floating Purple Switcher at Bottom-Right (as seen in Dribbble reference)
    function injectFloatingThemeButton() {
        if (!document.querySelector('.floating-theme-switch')) {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const floatBtn = document.createElement('button');
            floatBtn.type = 'button';
            floatBtn.className = 'floating-theme-switch';
            floatBtn.setAttribute('title', currentTheme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
            floatBtn.innerHTML = `<i class="fa-solid ${currentTheme === 'dark' ? 'fa-sun' : 'fa-moon'}"></i>`;
            document.body.appendChild(floatBtn);
        }
    }

    // Populate data-title on sidebar nav items for clean flyout tooltips in rail mode
    function initSidebarTooltips() {
        document.querySelectorAll('.sidebar-nav li').forEach(li => {
            const a = li.querySelector('a');
            if (a && !li.getAttribute('data-title')) {
                // Extract clean text ignoring badges and icons
                const clone = a.cloneNode(true);
                clone.querySelectorAll('i, .badge').forEach(el => el.remove());
                const cleanText = clone.textContent.trim();
                if (cleanText) {
                    li.setAttribute('data-title', cleanText);
                }
            }
        });
    }

    // Universal Command Palette (⌘ K / Ctrl K) Modal
    function initCommandPalette() {
        // Build modal DOM if not already present
        if (!document.getElementById('dsSearchModal')) {
            const modalBackdrop = document.createElement('div');
            modalBackdrop.id = 'dsSearchModal';
            modalBackdrop.className = 'ds-search-modal-backdrop';
            modalBackdrop.innerHTML = `
                <div class="ds-search-modal-card">
                    <div class="ds-search-modal-input-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="dsPaletteInput" placeholder="Quick search or jump to page (e.g. Properties, Residents, Invoices)..." autocomplete="off">
                        <span class="topbar-kbd-pill">ESC</span>
                    </div>
                    <div class="ds-search-results-list" id="dsPaletteResults">
                        <a href="../admin/index" class="ds-search-result-item"><i class="fa-solid fa-chart-pie"></i><div><strong>Dashboard</strong><div class="small text-muted">Overview, KPIs and metrics</div></div></a>
                        <a href="../admin/properties" class="ds-search-result-item"><i class="fa-solid fa-city"></i><div><strong>Properties & Units</strong><div class="small text-muted">Buildings, apartments, flats</div></div></a>
                        <a href="../admin/residents" class="ds-search-result-item"><i class="fa-solid fa-users"></i><div><strong>Residents Registry</strong><div class="small text-muted">Occupants, tenants, dependents</div></div></a>
                        <a href="../admin/finance" class="ds-search-result-item"><i class="fa-solid fa-wallet"></i><div><strong>Finance & Invoices</strong><div class="small text-muted">Collections, payments, ledger</div></div></a>
                        <a href="../admin/security" class="ds-search-result-item"><i class="fa-solid fa-shield-halved"></i><div><strong>Gate Visitor Passes</strong><div class="small text-muted">Check-in, guest access, logs</div></div></a>
                        <a href="../admin/maintenance" class="ds-search-result-item"><i class="fa-solid fa-screwdriver-wrench"></i><div><strong>Maintenance Dispatch</strong><div class="small text-muted">Work orders, repairs, tickets</div></div></a>
                        <a href="../admin/settings" class="ds-search-result-item"><i class="fa-solid fa-sliders"></i><div><strong>System Settings</strong><div class="small text-muted">Configuration, branding, security</div></div></a>
                    </div>
                </div>
            `;
            document.body.appendChild(modalBackdrop);

            // Close on backdrop click
            modalBackdrop.addEventListener('click', (e) => {
                if (e.target === modalBackdrop) {
                    closePalette();
                }
            });

            // Input filter logic
            const input = document.getElementById('dsPaletteInput');
            const list = document.getElementById('dsPaletteResults');
            if (input && list) {
                input.addEventListener('input', () => {
                    const q = input.value.toLowerCase().trim();
                    const items = list.querySelectorAll('.ds-search-result-item');
                    items.forEach(it => {
                        const txt = it.textContent.toLowerCase();
                        it.style.display = txt.includes(q) ? 'flex' : 'none';
                    });
                });
            }
        }

        function openPalette() {
            const modal = document.getElementById('dsSearchModal');
            if (modal) {
                modal.classList.add('show');
                const inp = document.getElementById('dsPaletteInput');
                if (inp) {
                    inp.value = '';
                    inp.focus();
                }
            }
        }

        function closePalette() {
            const modal = document.getElementById('dsSearchModal');
            if (modal) modal.classList.remove('show');
        }

        // Global Keyboard Shortcut: Ctrl+K or ⌘+K
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const modal = document.getElementById('dsSearchModal');
                if (modal && modal.classList.contains('show')) {
                    closePalette();
                } else {
                    openPalette();
                }
            }
            if (e.key === 'Escape') {
                closePalette();
            }
        });

        // Trigger on clicking search boxes
        document.addEventListener('click', (e) => {
            const searchBox = e.target.closest('.desktop-topbar-search, .topbar-search-box');
            if (searchBox && !e.target.matches('input[type="submit"]')) {
                // If user clicks on the search box container or shortcut pill
                if (e.target.closest('.topbar-kbd-pill') || searchBox.dataset.paletteTrigger === 'true') {
                    e.preventDefault();
                    openPalette();
                }
            }
        });
    }

    // Expose global helper
    window.toggleAppTheme = function () {
        const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        applyTheme(newTheme);
    };

    // Universal Custom Modal Engine for All Portals (Guarantees buttons always work)
    if (!window.openModal) {
        window.openModal = function(id) {
            const el = document.getElementById(id);
            if (!el) {
                console.warn('Modal not found:', id);
                return;
            }
            el.classList.add('show');
            el.classList.remove('hide');
            el.style.setProperty('display', 'flex', 'important');
            el.style.setProperty('pointer-events', 'auto', 'important');
            el.style.setProperty('visibility', 'visible', 'important');
            el.style.setProperty('opacity', '1', 'important');
        };
    }

    if (!window.closeModal) {
        window.closeModal = function(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('show');
            el.classList.add('hide');
            el.style.setProperty('display', 'none', 'important');
            el.style.setProperty('pointer-events', 'none', 'important');
        };
    }

    // Global listener for clicking backdrop or close buttons
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('custom-modal-backdrop')) {
            window.closeModal(e.target.id);
        }
        const closeBtn = e.target.closest('.close-modal, [data-modal-close]');
        if (closeBtn) {
            const parentModal = closeBtn.closest('.custom-modal-backdrop');
            if (parentModal) {
                window.closeModal(parentModal.id);
            }
        }
    });
})();

// Icon-rail sidebar: show the item name in a floating tooltip on hover/focus
(function () {
    if (window.__railTooltipInit) return;
    window.__railTooltipInit = true;
    var tip = null;
    function labelFor(a) {
        var tag = a.querySelector('.nav-name-tag');
        if (tag && tag.textContent.trim()) return tag.textContent.trim();
        var span = a.querySelector(':scope > span:not(.badge)');
        return a.getAttribute('data-title') || a.getAttribute('data-rail-title') ||
            (span && span.textContent.trim()) || a.getAttribute('title') || a.textContent.trim();
    }
    function show(a) {
        if (window.innerWidth < 992) return;
        var text = labelFor(a);
        if (!text) return;
        if (a.hasAttribute('title')) { a.setAttribute('data-rail-title', a.getAttribute('title')); a.removeAttribute('title'); }
        if (!tip) { tip = document.createElement('div'); tip.className = 'rail-tooltip'; document.body.appendChild(tip); }
        tip.textContent = text;
        var r = a.getBoundingClientRect();
        tip.style.left = (r.right + 12) + 'px';
        tip.style.top = (r.top + r.height / 2) + 'px';
        tip.classList.add('show');
    }
    function hide() { if (tip) tip.classList.remove('show'); }
    var sel = '.sidebar .sidebar-nav a, .sidebar .sidebar-footer-link, .sidebar .sidebar-footer-btn, .sidebar .sidebar-brand-ring, .sidebar .sidebar-brand-ring-logo';
    document.addEventListener('mouseover', function (e) { var a = e.target.closest && e.target.closest(sel); if (a) show(a); });
    document.addEventListener('mouseout', function (e) { var a = e.target.closest && e.target.closest(sel); if (a) hide(); });
    document.addEventListener('focusin', function (e) { var a = e.target.closest && e.target.closest(sel); if (a) show(a); });
    document.addEventListener('focusout', hide);
    document.addEventListener('scroll', hide, true);
})();
