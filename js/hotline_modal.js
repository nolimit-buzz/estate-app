/**
 * js/hotline_modal.js
 * Universal Emergency Direct-Dial Hotline Modal Engine
 * Allows residents, staff, and admins to dial or copy emergency contacts directly without triggering alarms
 */

(function () {
    'use strict';

    let hotlineCache = null;
    let fallbackContacts = [
        { id: 1, label: 'Main Gate Security Desk', phone_number: '08012345678', contact_type: 'internal_security', is_primary: 1 },
        { id: 2, label: 'Rapid Response Patrol Mobile', phone_number: '08087654321', contact_type: 'internal_security', is_primary: 0 },
        { id: 3, label: 'Chief Security Officer (CSO)', phone_number: '09066832352', contact_type: 'management', is_primary: 0 },
        { id: 4, label: 'Estate First-Aid / Medical Desk', phone_number: '08033334444', contact_type: 'medical', is_primary: 0 },
        { id: 5, label: 'State Police Emergency Division', phone_number: '112', contact_type: 'police', is_primary: 0 },
        { id: 6, label: 'State Fire & Rescue Service', phone_number: '119', contact_type: 'fire', is_primary: 0 }
    ];

    // Helper: Determine API Path based on current directory level
    function getApiPath() {
        const path = window.location.pathname.toLowerCase();
        if (path.includes('/resident/') || path.includes('/admin/') || path.includes('/staff/') || path.includes('/zone/') || path.includes('/superadmin/')) {
            return '../api/emergency.php?action=get_hotlines';
        }
        return 'api/emergency.php?action=get_hotlines';
    }

    // Helper: Category Icons & Color mappings
    function getCategoryConfig(type) {
        const map = {
            'internal_security': { icon: 'fa-shield-halved', label: 'Security & Patrol', class: 'type-security' },
            'police': { icon: 'fa-building-shield', label: 'Police Division', class: 'type-police' },
            'fire': { icon: 'fa-fire-extinguisher', label: 'Fire & Rescue', class: 'type-fire' },
            'medical': { icon: 'fa-truck-medical', label: 'Medical & Clinic', class: 'type-medical' },
            'management': { icon: 'fa-user-tie', label: 'Security Command', class: 'type-management' },
            'general': { icon: 'fa-phone', label: 'Emergency Line', class: 'type-general' }
        };
        return map[type] || map['general'];
    }

    // Helper: Create Hotline Card HTML
    function buildCardHtml(contact) {
        const cfg = getCategoryConfig(contact.contact_type);
        const cleanPhone = (contact.phone_number || '').replace(/[^\d+]/g, '');
        const isPrimary = parseInt(contact.is_primary, 10) === 1;

        return `
            <div class="hotline-card ${isPrimary ? 'is-primary' : ''}" data-search-term="${(contact.label + ' ' + contact.phone_number + ' ' + cfg.label).toLowerCase()}">
                <div class="hotline-card-icon ${cfg.class}">
                    <i class="fa-solid ${cfg.icon}"></i>
                </div>
                <div class="hotline-card-info">
                    <div class="hotline-card-label">
                        <span>${escapeHtml(contact.label)}</span>
                        ${isPrimary ? '<span class="hotline-badge-primary"><i class="fa-solid fa-star me-1"></i>Primary</span>' : ''}
                        <span class="hotline-badge-type">${cfg.label}</span>
                    </div>
                    <span class="hotline-card-number">${escapeHtml(contact.phone_number)}</span>
                </div>
                <div class="hotline-card-actions">
                    <a href="tel:${cleanPhone}" class="btn-hotline-call" title="Call directly from device">
                        <i class="fa-solid fa-phone"></i>
                        <span>Call</span>
                    </a>
                    <button type="button" class="btn-hotline-copy" data-phone="${escapeHtml(contact.phone_number)}" onclick="window.copyHotlineNumber('${escapeHtml(contact.phone_number)}', this)" title="Copy number to clipboard">
                        <i class="fa-regular fa-copy"></i>
                        <span>Copy</span>
                    </button>
                </div>
            </div>
        `;
    }

    // Helper: Create Guard on Duty Card HTML
    function buildGuardCardHtml(guard) {
        const cleanPhone = (guard.officer_phone || '').replace(/[^\d+]/g, '');
        const postLabel = guard.post_name || 'Active Security Patrol';
        const shiftLabel = guard.shift_name || 'Current Shift';

        return `
            <div class="hotline-card" data-search-term="${(guard.officer_name + ' ' + guard.officer_phone + ' ' + postLabel).toLowerCase()}">
                <div class="hotline-card-icon type-security">
                    <i class="fa-solid fa-person-military-pointing"></i>
                </div>
                <div class="hotline-card-info">
                    <div class="hotline-card-label">
                        <span>${escapeHtml(guard.officer_name)}</span>
                        <span class="hotline-badge-type">${escapeHtml(postLabel)}</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle py-0 px-1" style="font-size: 0.65rem;">On Duty</span>
                    </div>
                    <span class="hotline-card-number">${escapeHtml(guard.officer_phone || 'Radio Channel Only')}</span>
                </div>
                <div class="hotline-card-actions">
                    ${cleanPhone ? `
                        <a href="tel:${cleanPhone}" class="btn-hotline-call" title="Call Guard Desk">
                            <i class="fa-solid fa-phone"></i>
                            <span>Call</span>
                        </a>
                        <button type="button" class="btn-hotline-copy" data-phone="${escapeHtml(guard.officer_phone)}" onclick="window.copyHotlineNumber('${escapeHtml(guard.officer_phone)}', this)" title="Copy number">
                            <i class="fa-regular fa-copy"></i>
                            <span>Copy</span>
                        </button>
                    ` : '<span class="text-muted small">No Direct Phone</span>'}
                </div>
            </div>
        `;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Build or ensure Modal in DOM
    function ensureModal() {
        let modal = document.getElementById('estateHotlineModal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'estateHotlineModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="hotline-modal-badge">
                                <i class="fa-solid fa-phone-volume"></i>
                            </div>
                            <div>
                                <h5 class="modal-title fw-bold mb-0 text-white" style="letter-spacing: -0.01em;">Emergency Hotlines</h5>
                                <small class="text-white-50">Direct-Dial Contacts &bull; 24/7 Rapid Response</small>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-3 p-sm-4">
                        <!-- Direct Dial Safe Advisory -->
                        <div class="hotline-safety-notice">
                            <span class="hotline-safe-badge">
                                <i class="fa-solid fa-shield-check me-1"></i> Direct Dial Only
                            </span>
                            <p>
                                <strong>Calls here are direct phone dials.</strong> They connect you directly to the post or responder and will <strong>NOT</strong> sound sirens or trigger estate-wide panic alarms.
                            </p>
                        </div>

                        <!-- Real-time Filter Search -->
                        <div class="hotline-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="hotlineSearchInput" class="hotline-search-input" placeholder="Search contact (e.g., Gate, Police, Clinic, Fire, CSO)..." autocomplete="off">
                        </div>

                        <!-- Contact Cards List -->
                        <div id="hotlineCardsContainer" class="hotline-cards-list">
                            <div class="text-center py-4 text-muted">
                                <i class="fa-solid fa-circle-notch fa-spin text-danger mb-2" style="font-size: 1.5rem;"></i>
                                <div>Loading emergency directory...</div>
                            </div>
                        </div>

                        <!-- Active Duty Guards Section (Populated dynamically) -->
                        <div id="hotlineGuardsSection" class="hotline-guard-section d-none">
                            <div class="hotline-guard-title">
                                <i class="fa-solid fa-person-military-pointing text-success"></i>
                                <span>Security Personnel Currently Clocked In</span>
                            </div>
                            <div id="hotlineGuardsContainer" class="d-flex flex-column gap-2"></div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <div class="text-muted small d-none d-sm-block">
                            <i class="fa-solid fa-lock text-success me-1"></i> Private Direct Telephone Numbers
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm px-3 rounded-pill" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Bind live search input
        const searchInput = modal.querySelector('#hotlineSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const term = (this.value || '').trim().toLowerCase();
                const cards = modal.querySelectorAll('.hotline-card');
                cards.forEach(card => {
                    const haystack = card.getAttribute('data-search-term') || '';
                    if (!term || haystack.includes(term)) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }

        return modal;
    }

    // Render contacts to Modal Container
    function renderContacts(contacts, guards) {
        const container = document.getElementById('hotlineCardsContainer');
        if (!container) return;

        if (!contacts || contacts.length === 0) {
            contacts = fallbackContacts;
        }

        let html = '';
        contacts.forEach(c => {
            html += buildCardHtml(c);
        });
        container.innerHTML = html;

        // Render Guards on duty if any
        const guardSection = document.getElementById('hotlineGuardsSection');
        const guardContainer = document.getElementById('hotlineGuardsContainer');
        if (guardSection && guardContainer) {
            if (guards && guards.length > 0) {
                let gHtml = '';
                guards.forEach(g => {
                    gHtml += buildGuardCardHtml(g);
                });
                guardContainer.innerHTML = gHtml;
                guardSection.classList.remove('d-none');
            } else {
                guardSection.classList.add('d-none');
            }
        }
    }

    // Fetch Contacts from Server
    function loadHotlines(callback) {
        if (hotlineCache) {
            if (callback) callback(hotlineCache.contacts, hotlineCache.guards_on_duty);
            return;
        }

        // Render fallbacks immediately for zero delay
        renderContacts(fallbackContacts, []);

        fetch(getApiPath())
            .then(r => r.json())
            .then(data => {
                if (data && data.success && data.contacts && data.contacts.length > 0) {
                    hotlineCache = data;
                    renderContacts(data.contacts, data.guards_on_duty || []);
                    if (callback) callback(data.contacts, data.guards_on_duty || []);
                } else {
                    renderContacts(fallbackContacts, []);
                    if (callback) callback(fallbackContacts, []);
                }
            })
            .catch(err => {
                console.warn('Emergency hotline API offline, using cached emergency directory:', err);
                renderContacts(fallbackContacts, []);
                if (callback) callback(fallbackContacts, []);
            });
    }

    // Global: Copy Number with Feedback
    window.copyHotlineNumber = function (text, btnElement) {
        if (!text) return;

        function showSuccess() {
            if (btnElement) {
                const origHtml = btnElement.innerHTML;
                btnElement.classList.add('copied');
                btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i> <span>Copied!</span>';
                setTimeout(() => {
                    btnElement.classList.remove('copied');
                    btnElement.innerHTML = origHtml;
                }, 2000);
            }
            if (window.EstateNotifications && typeof window.EstateNotifications.showToast === 'function') {
                window.EstateNotifications.showToast(`Number ${text} copied to clipboard`, 'success');
            } else if (typeof window.showToast === 'function') {
                window.showToast(`Number ${text} copied to clipboard`, 'success');
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showSuccess).catch(() => {
                fallbackCopy(text);
                showSuccess();
            });
        } else {
            fallbackCopy(text);
            showSuccess();
        }
    };

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            console.error('Copy fallback failed:', e);
        }
        document.body.removeChild(ta);
    }

    // Global: Open Modal Programmatically
    window.openEstateHotlineModal = function () {
        const modalEl = ensureModal();
        loadHotlines();

        if (window.bootstrap && window.bootstrap.Modal) {
            const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        } else {
            // Fallback if bootstrap object is delayed
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            modalEl.removeAttribute('aria-hidden');
            let backdrop = document.querySelector('.modal-backdrop');
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(backdrop);
            }
            const closeBtn = modalEl.querySelector('[data-bs-dismiss="modal"]');
            if (closeBtn) {
                closeBtn.onclick = function () {
                    modalEl.classList.remove('show');
                    modalEl.style.display = 'none';
                    if (backdrop) backdrop.remove();
                };
            }
        }
    };

    // Attach Click Handlers to all .estate-hotline-btn buttons
    document.addEventListener('DOMContentLoaded', function () {
        ensureModal();
        loadHotlines();

        document.body.addEventListener('click', function (e) {
            const btn = e.target.closest('.estate-hotline-btn, [data-trigger="estate-hotline"]');
            if (btn) {
                e.preventDefault();
                window.openEstateHotlineModal();
            }
        });
    });

})();
