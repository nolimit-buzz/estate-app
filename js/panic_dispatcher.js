/**
 * js/panic_dispatcher.js
 * Interactive Panic Button Modal, Configurable Countdown Confirmation & Multi-Channel Dispatch Flow
 */

(function () {
    'use strict';

    let configCache = null;
    let countdownTimer = null;
    let countdownSeconds = 5;
    let selectedCategory = null;
    let userLocation = { latitude: null, longitude: null };

    // Request GPS Coordinates silently in background
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                userLocation.latitude = pos.coords.latitude;
                userLocation.longitude = pos.coords.longitude;
            },
            err => console.debug('Location permission declined or unavailable')
        );
    }

    // Fetch Estate Categories, Settings, Actions and Hotlines
    function fetchConfig(callback) {
        if (configCache) {
            if (callback) callback(configCache);
            return;
        }
        const apiPath = window.location.pathname.includes('/resident/') || window.location.pathname.includes('/admin/') || window.location.pathname.includes('/zone/') || window.location.pathname.includes('/staff/')
            ? '../api/emergency.php?action=get_config'
            : 'api/emergency.php?action=get_config';

        fetch(apiPath)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    configCache = data;
                    if (data.countdown_seconds) {
                        countdownSeconds = parseInt(data.countdown_seconds, 10) || 5;
                    }
                    if (callback) callback(data);
                }
            })
            .catch(e => console.error('Failed to load emergency config:', e));
    }

    // Create Modal HTML Structure in DOM
    function ensureModal() {
        let modalEl = document.getElementById('estatePanicModal');
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = 'estatePanicModal';
            modalEl.className = 'modal fade';
            modalEl.tabIndex = -1;
            modalEl.setAttribute('aria-hidden', 'true');
            modalEl.innerHTML = `
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">
                        <!-- Step 1: Category Choice (Clean, No Icon Artifacts) -->
                        <div id="panic-step-choice">
                            <div class="modal-header bg-danger text-white px-4 py-3 border-0 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 800;">
                                        SOS
                                    </div>
                                    <div>
                                        <h5 class="modal-title fw-bold mb-0" style="font-family: 'Outfit', sans-serif; letter-spacing: -0.01em;">Emergency Panic Dispatch</h5>
                                        <small class="text-white-50">Instant dispatch to Security Command &amp; Duty Officers</small>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body p-4" style="background: #f8fafc;">
                                <div class="text-center mb-3">
                                    <h4 class="fw-bold text-dark mb-1" style="font-family: 'Outfit', sans-serif;">Select Emergency Category</h4>
                                    <p class="text-muted small mb-0">Choose the nature of your emergency. You will have a safety confirmation countdown before the alert blares out.</p>
                                </div>

                                <div id="panic-categories-container" class="row g-2 g-md-3">
                                    <div class="col-12 text-center py-4">
                                        <div class="spinner-border text-danger" role="status"></div>
                                        <div class="text-muted small mt-2">Loading emergency categories...</div>
                                    </div>
                                </div>

                                <div class="mt-3 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between text-muted small gap-2">
                                    <span><i class="fa-solid fa-shield-halved text-success me-1"></i> Security gates on 24/7 duty</span>
                                    <span><i class="fa-solid fa-phone-volume text-primary me-1"></i> Direct dial contacts provided upon selection</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Safety Countdown & Explicit Confirmation -->
                        <div id="panic-step-countdown" style="display: none;">
                            <div class="modal-body p-4 p-md-5 text-center bg-danger text-white">
                                <div class="mb-3 position-relative d-inline-block">
                                    <div id="panic-countdown-circle" style="width: 100px; height: 100px; border-radius: 50%; border: 5px solid #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: 900; font-family: 'Outfit', sans-serif; box-shadow: 0 0 25px rgba(255,255,255,0.4);">
                                        5
                                    </div>
                                </div>

                                <div class="badge bg-white text-danger fw-bold rounded-pill px-3 py-1 mb-2 text-uppercase" id="countdown-stakeholder-badge" style="font-size: 0.75rem;">
                                    Target: Security &amp; Management
                                </div>

                                <h3 class="fw-bold mb-1" id="countdown-category-title" style="font-family: 'Outfit', sans-serif;">Security Threat</h3>
                                <p class="text-white-50 mb-4" style="max-width: 500px; margin: 0 auto;">
                                    DISPATCHING EMERGENCY ALARM &amp; SOUNDING SIRENS IN <span id="countdown-num-text" class="fw-bold text-white">5</span> SECONDS...
                                    Confirm now or cancel to abort false alarm.
                                </p>
                                
                                <div class="d-flex flex-wrap align-items-center justify-content-center gap-3">
                                    <button type="button" id="btnAbortPanic" class="btn btn-light btn-lg rounded-pill px-4 text-danger fw-bold shadow">
                                        <i class="fa-solid fa-xmark me-1"></i> Cancel SOS (False Alarm)
                                    </button>
                                    <button type="button" id="btnInstantDispatch" class="btn btn-warning btn-lg rounded-pill px-4 text-dark fw-bold shadow">
                                        <i class="fa-solid fa-bolt me-1"></i> Confirm &amp; Dispatch SOS Now
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Success, Direct Hotlines & WhatsApp Sharing -->
                        <div id="panic-step-success" style="display: none;">
                            <div class="modal-header bg-success text-white px-4 py-3 border-0 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-circle-check fs-4"></i>
                                    <div>
                                        <h5 class="modal-title fw-bold mb-0">Emergency Alert Dispatched!</h5>
                                        <small class="text-white-50" id="success-alert-code">Code: SOS-0000</small>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body p-4 bg-white">
                                <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 border-0 shadow-sm rounded-3 mb-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="fa-solid fa-person-running fs-2 text-warning"></i>
                                        <div>
                                            <strong class="text-dark">Security Team Mobilized!</strong>
                                            <div class="small text-secondary">Active gate guards and control room have been alerted with your residence location. Stand by in safety.</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- WhatsApp Share Action -->
                                <div id="panic-whatsapp-box" class="p-3 rounded-3 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-2 border" style="background: #f0fdf4; border-color: #86efac !important;">
                                    <div>
                                        <strong class="text-success small d-block"><i class="fa-brands fa-whatsapp fs-5 me-1"></i> Notify Residents &amp; Stakeholders via WhatsApp</strong>
                                        <span class="text-secondary small">Share the active incident summary directly to WhatsApp groups or contacts</span>
                                    </div>
                                    <a href="#" id="btnShareWhatsApp" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                                        <i class="fa-brands fa-whatsapp me-1"></i> Share on WhatsApp
                                    </a>
                                </div>

                                <h6 class="fw-bold text-dark mb-2" style="font-family: 'Outfit', sans-serif;">
                                    <i class="fa-solid fa-phone me-1 text-primary"></i> Direct Dial Estate Hotlines:
                                </h6>
                                <p class="text-muted small mb-3">Tap any phone button below to connect immediately:</p>

                                <div id="panic-hotlines-container" class="row g-2 mb-4">
                                    <!-- Populated dynamically -->
                                </div>

                                <div id="panic-on-duty-guards-container" class="border rounded-3 p-3 bg-light">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <strong class="small text-secondary text-uppercase fw-bold"><i class="fa-solid fa-shield-halved text-success me-1"></i> Security Officers On Duty Snapshot:</strong>
                                    </div>
                                    <div id="panic-guards-list" class="d-flex flex-column gap-2">
                                        <!-- Populated dynamically -->
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer bg-light px-4 py-3 d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                <button type="button" id="btnMarkFalseAlarm" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                                    <i class="fa-solid fa-circle-exclamation me-1"></i> Cancel / It was a False Alarm
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modalEl);

            // Hook abort & instant dispatch buttons
            document.getElementById('btnAbortPanic').addEventListener('click', abortPanic);
            document.getElementById('btnInstantDispatch').addEventListener('click', dispatchAlertNow);

            // Reset timer on modal close
            modalEl.addEventListener('hidden.bs.modal', function () {
                if (countdownTimer) {
                    clearInterval(countdownTimer);
                    countdownTimer = null;
                }
            });
        }
        return modalEl;
    }

    // Open Modal and Populate Dynamic Categories
    window.openEstatePanicModal = function () {
        const modalEl = ensureModal();
        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

        // Reset views
        document.getElementById('panic-step-choice').style.display = 'block';
        document.getElementById('panic-step-countdown').style.display = 'none';
        document.getElementById('panic-step-success').style.display = 'none';

        bsModal.show();

        fetchConfig(data => {
            renderCategories(data.categories || []);
        });
    };

    // Render Dynamic Categories Grid (NO ICON / EMOJI ARTIFACTS - Clean, Professional Design)
    function renderCategories(categories) {
        const container = document.getElementById('panic-categories-container');
        if (!container) return;

        if (categories.length === 0) {
            container.innerHTML = `
                <div class="col-12 text-center py-4">
                    <p class="text-muted">No emergency categories configured by estate management yet.</p>
                </div>
            `;
            return;
        }

        let html = '';
        categories.forEach(cat => {
            const color = cat.color || '#ef4444';
            const prio = cat.priority || 'critical';
            const stakeholders = cat.target_stakeholders || 'all_residents';
            
            let stakeholderLabel = 'All Estate Residents';
            if (stakeholders === 'guards_only') stakeholderLabel = 'Security Gate Only';
            else if (stakeholders === 'guards_and_admin') stakeholderLabel = 'Security & Admins';
            else if (stakeholders === 'guards_and_medical') stakeholderLabel = 'Guards & Medical';

            html += `
                <div class="col-12 col-md-6">
                    <div class="emergency-cat-card p-3 rounded-3 h-100 d-flex flex-column justify-content-between shadow-sm border position-relative"
                         style="background: #ffffff; cursor: pointer; transition: all 0.2s ease; border-left: 5px solid ${color} !important;"
                         onclick="selectEmergencyCategory(${cat.id}, '${escapeHtml(cat.category_name)}', '${color}', '${stakeholders}', '${escapeHtml(stakeholderLabel)}')"
                         onmouseover="this.style.transform='translateY(-2px)'; this.style.borderColor='${color}'; this.style.boxShadow='0 6px 15px rgba(0,0,0,0.08)';"
                         onmouseout="this.style.transform='none'; this.style.borderColor='#e2e8f0'; this.style.boxShadow='none';">
                        
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.95rem;">${escapeHtml(cat.category_name)}</h6>
                                <span class="badge ${prio === 'critical' ? 'bg-danger' : (prio === 'high' ? 'bg-warning text-dark' : 'bg-info text-dark')} rounded-pill" style="font-size: 0.65rem; text-transform: uppercase;">
                                    ${prio}
                                </span>
                            </div>
                            <p class="text-muted small mb-2" style="font-size: 0.8rem; line-height: 1.35;">${escapeHtml(cat.use_case_description)}</p>
                        </div>

                        <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-1">
                            <span class="badge bg-light text-secondary border" style="font-size: 0.68rem;">
                                <i class="fa-solid fa-users me-1"></i> ${stakeholderLabel}
                            </span>
                            <span class="text-danger small fw-bold" style="font-size: 0.75rem;">
                                Select <i class="fa-solid fa-chevron-right ms-1"></i>
                            </span>
                        </div>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    // Step 2: User Selected a Category -> Confirm Before Triggering Alarm & Starting Countdown
    window.selectEmergencyCategory = async function (catId, catName, color, targetStakeholders, stakeholderLabel) {
        const confirmed = window.EstateDialog ? await EstateDialog.confirm({
            title: 'Trigger Emergency Alarm',
            message: 'Are you sure you want to trigger the alarm?',
            type: 'danger',
            confirmText: 'Yes, Trigger Alarm',
            cancelText: 'Cancel'
        }) : confirm('Are you sure you want to trigger the alarm?');

        if (!confirmed) {
            return;
        }

        selectedCategory = { 
            id: catId, 
            name: catName, 
            color: color, 
            target_stakeholders: targetStakeholders,
            stakeholder_label: stakeholderLabel 
        };

        document.getElementById('panic-step-choice').style.display = 'none';
        document.getElementById('panic-step-countdown').style.display = 'block';

        const titleEl = document.getElementById('countdown-category-title');
        titleEl.innerText = catName;

        const stBadge = document.getElementById('countdown-stakeholder-badge');
        if (stBadge) stBadge.innerText = `Target: ${stakeholderLabel || 'Estate Security'}`;

        // Begin Configured Countdown
        const configuredSeconds = (configCache && configCache.countdown_seconds) ? parseInt(configCache.countdown_seconds, 10) : 5;
        countdownSeconds = configuredSeconds > 0 ? configuredSeconds : 5;
        updateCountdownUI();

        if (countdownTimer) clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            countdownSeconds--;
            updateCountdownUI();
            if (countdownSeconds <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                dispatchAlertNow();
            }
        }, 1000);
    };

    function updateCountdownUI() {
        const circle = document.getElementById('panic-countdown-circle');
        const numText = document.getElementById('countdown-num-text');
        if (circle) circle.innerText = countdownSeconds;
        if (numText) numText.innerText = countdownSeconds;
    }

    function abortPanic() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        document.getElementById('panic-step-countdown').style.display = 'none';
        document.getElementById('panic-step-choice').style.display = 'block';
    }

    // Step 3: Dispatch Alert to Backend API
    function dispatchAlertNow() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        const apiPath = window.location.pathname.includes('/resident/') || window.location.pathname.includes('/admin/') || window.location.pathname.includes('/zone/') || window.location.pathname.includes('/staff/')
            ? '../api/emergency.php'
            : 'api/emergency.php';

        const formData = new URLSearchParams();
        formData.append('action', 'trigger_panic');
        if (selectedCategory && selectedCategory.id) {
            formData.append('category_id', selectedCategory.id);
            formData.append('category_name', selectedCategory.name);
            if (selectedCategory.target_stakeholders) {
                formData.append('target_stakeholders', selectedCategory.target_stakeholders);
            }
        }
        if (userLocation.latitude) {
            formData.append('latitude', userLocation.latitude);
            formData.append('longitude', userLocation.longitude);
        }

        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showSuccessScreen(res);
            } else {
                if (window.EstateDialog) {
                    EstateDialog.toast({ type: 'error', title: 'Dispatch Error', message: res.error || 'Please call the security gate directly' });
                }
                abortPanic();
            }
        })
        .catch(err => {
            console.error('Dispatch failure:', err);
            if (window.EstateDialog) {
                EstateDialog.toast({ type: 'error', title: 'Network Failure', message: 'Emergency request failed. Please dial your security desk directly!' });
            }
            abortPanic();
        });
    }

    // Render Step 3: Success Screen with WhatsApp share & Hotlines
    function showSuccessScreen(res) {
        document.getElementById('panic-step-countdown').style.display = 'none';
        document.getElementById('panic-step-success').style.display = 'block';

        const codeEl = document.getElementById('success-alert-code');
        if (codeEl) codeEl.innerText = `Alert Code: ${res.alert_code || 'SOS-CONFIRMED'}`;

        // WhatsApp Share Button
        const btnWA = document.getElementById('btnShareWhatsApp');
        if (btnWA && res.whatsapp_share_url) {
            btnWA.href = res.whatsapp_share_url;
        }

        // Render Hotlines
        const hotlinesContainer = document.getElementById('panic-hotlines-container');
        if (hotlinesContainer) {
            let hHtml = '';
            (res.hotlines || []).forEach(h => {
                const isPrim = h.is_primary == 1;
                hHtml += `
                    <div class="col-12 col-sm-6">
                        <a href="tel:${escapeHtml(h.phone_number)}" 
                           class="btn ${isPrim ? 'btn-danger' : 'btn-outline-danger'} w-100 py-2 px-3 rounded-pill text-start d-flex align-items-center justify-content-between shadow-sm"
                           style="text-decoration: none;">
                            <div class="d-flex align-items-center gap-2 text-truncate">
                                <i class="fa-solid fa-phone-volume fs-5"></i>
                                <div class="text-truncate">
                                    <strong class="d-block text-truncate" style="font-size: 0.85rem;">${escapeHtml(h.label)}</strong>
                                    <span class="small opacity-75">${escapeHtml(h.phone_number)}</span>
                                </div>
                            </div>
                            <span class="badge bg-white text-danger fw-bold rounded-pill px-2 py-1 ms-2" style="font-size: 0.7rem;">TAP TO CALL</span>
                        </a>
                    </div>
                `;
            });
            hotlinesContainer.innerHTML = hHtml || '<div class="col-12 text-muted small">No direct numbers listed. Please remain on stand-by.</div>';
        }

        // Render Guards on Duty
        const guardsContainer = document.getElementById('panic-guards-list');
        if (guardsContainer) {
            let gHtml = '';
            (res.guards_on_duty || []).forEach(g => {
                gHtml += `
                    <div class="d-flex align-items-center justify-content-between p-2 rounded-2 bg-white border">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #475569; font-size: 0.8rem; font-weight: 700;">
                                <i class="fa-solid fa-shield-halved text-primary"></i>
                            </div>
                            <div>
                                <strong class="text-dark d-block" style="font-size: 0.85rem;">${escapeHtml(g.officer_name)}</strong>
                                <small class="text-muted" style="font-size: 0.75rem;">Station: <strong>${escapeHtml(g.post_name || 'Main Gate')}</strong></small>
                            </div>
                        </div>
                        ${g.officer_phone ? `
                            <a href="tel:${escapeHtml(g.officer_phone)}" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-phone me-1"></i> Call Guard
                            </a>
                        ` : '<span class="badge bg-light text-muted">Radio Alerted</span>'}
                    </div>
                `;
            });
            guardsContainer.innerHTML = gHtml || '<div class="text-muted small">Active security guards have been radio-dispatched to your location.</div>';
        }

        // False alarm handler
        const btnFalse = document.getElementById('btnMarkFalseAlarm');
        if (btnFalse && res.alert_id) {
            btnFalse.onclick = function () {
                const performCancel = () => {
                    const apiPath = window.location.pathname.includes('/resident/') || window.location.pathname.includes('/admin/') || window.location.pathname.includes('/zone/') || window.location.pathname.includes('/staff/')
                        ? '../api/emergency.php'
                        : 'api/emergency.php';
                    fetch(apiPath, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=resolve_alert&alert_id=${res.alert_id}&status=false_alarm&resolution_action=Verified False Alarm - Resident Safe&resolution_notes=Resident canceled alert / false alarm`
                    }).then(() => {
                        const modalEl = document.getElementById('estatePanicModal');
                        if (modalEl) bootstrap.Modal.getInstance(modalEl).hide();
                        if (window.EstateDialog) {
                            EstateDialog.toast({ type: 'info', title: 'Alert Cancelled', message: 'Emergency alert has been cancelled.' });
                        }
                        if (typeof pollActiveAlerts === 'function') pollActiveAlerts();
                    });
                };

                if (window.EstateDialog) {
                    EstateDialog.confirm({
                        title: 'Cancel Emergency Alert?',
                        message: 'Are you sure you want to cancel this emergency alert? This will mark it as a false alarm.',
                        type: 'warning',
                        confirmText: 'Yes, Cancel Alert',
                        cancelText: 'Keep SOS Active'
                    }).then(confirmed => {
                        if (confirmed) performCancel();
                    });
                } else {
                    performCancel();
                }
            };
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Attach to triggers on DOM Load
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.btn-estate-panic-trigger').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                openEstatePanicModal();
            });
        });
    });

})();
