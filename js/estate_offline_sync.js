/**
 * js/estate_offline_sync.js
 * High-resilience Offline Mode & USSD Synchronization Engine for Estate Security Gate
 * Allows guards to verify visitor passes, generate new access codes offline, and automatically sync when restored.
 */
(function() {
    'use strict';

    const STORAGE_KEY_BUNDLE = 'estate_gate_offline_bundle';
    const STORAGE_KEY_QUEUE  = 'estate_gate_offline_queue';
    const SYNC_ENDPOINT      = (window.location.pathname.includes('/staff/') || window.location.pathname.includes('/admin/')) 
                                ? '../api/sync_offline.php' 
                                : 'api/sync_offline.php';

    window.EstateOfflineSync = {
        isOnline: navigator.onLine,
        bundle: null,
        queue: [],

        init: function() {
            this.loadStoredData();
            this.renderStatusWidget();
            this.bindNetworkEvents();
            this.interceptGuardForms();

            if (this.isOnline) {
                this.downloadFreshBundle();
                this.processSyncQueue();
            }

            // Auto-refresh bundle every 5 minutes when online
            setInterval(() => {
                if (navigator.onLine) {
                    this.downloadFreshBundle();
                    this.processSyncQueue();
                }
            }, 300000);
        },

        loadStoredData: function() {
            try {
                const rawBundle = localStorage.getItem(STORAGE_KEY_BUNDLE);
                if (rawBundle) this.bundle = JSON.parse(rawBundle);

                const rawQueue = localStorage.getItem(STORAGE_KEY_QUEUE);
                this.queue = rawQueue ? JSON.parse(rawQueue) : [];
            } catch (e) {
                console.warn('[OfflineSync] Failed to parse local storage:', e);
                this.queue = [];
            }
        },

        saveQueue: function() {
            try {
                localStorage.setItem(STORAGE_KEY_QUEUE, JSON.stringify(this.queue));
                this.updateWidgetUI();
            } catch (e) {
                console.error('[OfflineSync] Storage error saving queue:', e);
            }
        },

        bindNetworkEvents: function() {
            window.addEventListener('online', () => {
                this.isOnline = true;
                this.updateWidgetUI();
                this.showToast('success', 'Internet Restored', 'Reconnected to Cloud Server! Synchronizing queued passes...');
                this.processSyncQueue();
                this.downloadFreshBundle();
            });

            window.addEventListener('offline', () => {
                this.isOnline = false;
                this.updateWidgetUI();
                this.showToast('warning', 'Offline Mode Engaged', 'Internet connection lost. Local gate storage & offline code generation active.');
            });
        },

        renderStatusWidget: function() {
            if (document.getElementById('estateOfflineWidget')) return;

            const widget = document.createElement('div');
            widget.id = 'estateOfflineWidget';
            widget.className = 'estate-offline-pill';
            widget.innerHTML = `
                <div class="pill-content" id="offlinePillContent">
                    <span class="status-indicator" id="offlineStatusDot"></span>
                    <span class="status-text" id="offlineStatusText">Checking connection...</span>
                    <button type="button" class="gen-code-btn" id="offlineGenBtn" title="Generate New Gate Pass Code Offline">
                        <i class="fa-solid fa-ticket"></i> New Pass Code
                    </button>
                    <button type="button" class="sync-now-btn" id="offlineSyncBtn" style="display:none;" title="Click to synchronize offline actions">
                        <i class="fa-solid fa-arrows-rotate"></i> Sync (<span id="offlineQueueCount">0</span>)
                    </button>
                </div>
            `;

            // Append CSS
            const style = document.createElement('style');
            style.textContent = `
                .estate-offline-pill {
                    position: fixed;
                    bottom: 24px;
                    right: 24px;
                    z-index: 9999;
                    background: rgba(15, 23, 42, 0.95);
                    backdrop-filter: blur(8px);
                    color: #fff;
                    padding: 8px 16px;
                    border-radius: 9999px;
                    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.3);
                    border: 1px solid rgba(255, 255, 255, 0.15);
                    font-family: inherit;
                    font-size: 0.82rem;
                    font-weight: 500;
                    display: flex;
                    align-items: center;
                    transition: all 0.3s ease;
                }
                .pill-content {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .status-indicator {
                    width: 9px;
                    height: 9px;
                    border-radius: 50%;
                    display: inline-block;
                }
                .status-indicator.online {
                    background: #10b981;
                    box-shadow: 0 0 8px #10b981;
                }
                .status-indicator.offline {
                    background: #f59e0b;
                    box-shadow: 0 0 8px #f59e0b;
                    animation: pulse-dot 1.8s infinite;
                }
                @keyframes pulse-dot {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: 0.5; transform: scale(1.2); }
                }
                .gen-code-btn {
                    background: #f59e0b;
                    color: #0f172a;
                    border: none;
                    border-radius: 9999px;
                    padding: 3px 12px;
                    font-size: 0.75rem;
                    font-weight: 700;
                    cursor: pointer;
                    margin-left: 6px;
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                    transition: all 0.2s;
                }
                .gen-code-btn:hover { background: #d97706; color: #fff; }
                .sync-now-btn {
                    background: #2563eb;
                    color: #fff;
                    border: none;
                    border-radius: 9999px;
                    padding: 3px 10px;
                    font-size: 0.75rem;
                    cursor: pointer;
                    margin-left: 4px;
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    transition: background 0.2s;
                }
                .sync-now-btn:hover { background: #1d4ed8; }
                .sync-spin { animation: spin 1s infinite linear; }
                @keyframes spin { 100% { transform: rotate(360deg); } }
            `;

            document.head.appendChild(style);
            document.body.appendChild(widget);

            document.getElementById('offlineGenBtn').addEventListener('click', (e) => {
                e.preventDefault();
                this.openGenerateModal();
            });

            document.getElementById('offlineSyncBtn').addEventListener('click', (e) => {
                e.preventDefault();
                this.processSyncQueue(true);
            });

            this.updateWidgetUI();
        },

        updateWidgetUI: function() {
            const dot = document.getElementById('offlineStatusDot');
            const txt = document.getElementById('offlineStatusText');
            const syncBtn = document.getElementById('offlineSyncBtn');
            const cntSpan = document.getElementById('offlineQueueCount');

            if (!dot || !txt) return;

            const qCount = this.queue.length;

            if (this.isOnline) {
                dot.className = 'status-indicator online';
                if (qCount > 0) {
                    txt.textContent = 'Online • Ready to Sync';
                    syncBtn.style.display = 'inline-flex';
                    cntSpan.textContent = qCount;
                } else {
                    txt.textContent = 'Gate Online • Synced';
                    syncBtn.style.display = 'none';
                }
            } else {
                dot.className = 'status-indicator offline';
                txt.textContent = 'Offline Mode (Local Storage)';
                if (qCount > 0) {
                    syncBtn.style.display = 'inline-flex';
                    cntSpan.textContent = qCount;
                } else {
                    syncBtn.style.display = 'none';
                }
            }
        },

        downloadFreshBundle: function() {
            if (!this.isOnline) return;

            fetch(SYNC_ENDPOINT, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    if (data && data.status === 'success' && data.bundle) {
                        this.bundle = data.bundle;
                        localStorage.setItem(STORAGE_KEY_BUNDLE, JSON.stringify(data.bundle));
                        console.log(`[OfflineSync] Bundle cached: ${data.visitors_count} visitors, ${data.vehicles_count} vehicles, ${data.residents_count || 0} residents.`);
                    }
                })
                .catch(err => {
                    console.warn('[OfflineSync] Could not download offline bundle:', err);
                });
        },

        /**
         * Generate a secure unique offline gate pass code
         * Format: EST-XXXXXX (6 alphanumeric random uppercase characters)
         */
        generatePassCode: function() {
            const chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            let code = 'EST-';
            for (let i = 0; i < 6; i++) {
                code += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return code;
        },

        /**
         * Open Offline Pass Generator Modal
         */
        openGenerateModal: function(defaults = {}) {
            let modalEl = document.getElementById('estateOfflineGenModal');
            if (modalEl) modalEl.remove();

            const residents = (this.bundle && this.bundle.residents) ? this.bundle.residents : [];
            const gates = (this.bundle && this.bundle.gates) ? this.bundle.gates : ['Main Gate', 'North Gate', 'South Gate'];

            let residentOptionsHtml = '<option value="">-- Select Resident / Destination Unit --</option>';
            residents.forEach(r => {
                const label = `${r.resident_name} (${r.building_name || ''} Flat ${r.flat_number || 'Unit'})`;
                residentOptionsHtml += `<option value="${r.user_id || r.resident_id}" data-flat="${r.flat_id || ''}" data-name="${r.resident_name}" data-flatnum="${r.flat_number || ''}">${label}</option>`;
            });

            let gateOptionsHtml = '';
            gates.forEach(g => {
                gateOptionsHtml += `<option value="${g}">${g}</option>`;
            });

            const div = document.createElement('div');
            div.id = 'estateOfflineGenModal';
            div.innerHTML = `
                <div style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.7); z-index:10001; display:flex; align-items:center; justify-content:center; padding:15px; backdrop-filter:blur(4px);">
                    <div style="background:#fff; width:100%; max-width:500px; border-radius:18px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); overflow:hidden; font-family:inherit; animation:modalPop 0.25s ease-out;">
                        <div style="background:#0f172a; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="background:#f59e0b; color:#0f172a; width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; font-weight:800;">
                                    <i class="fa-solid fa-ticket"></i>
                                </div>
                                <div>
                                    <h5 style="margin:0; font-size:1.05rem; font-weight:700;">Generate Gate Pass (Offline)</h5>
                                    <small style="color:#94a3b8; font-size:0.75rem;">Create &amp; issue visitor access code without internet</small>
                                </div>
                            </div>
                            <button type="button" id="closeOffGenModal" style="background:none; border:none; color:#cbd5e1; font-size:1.4rem; cursor:pointer; line-height:1;">&times;</button>
                        </div>
                        
                        <form id="offlineGenForm" style="padding:22px 24px; max-height:calc(85vh - 70px); overflow-y:auto;">
                            <div style="margin-bottom:14px;">
                                <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Visitor Full Name *</label>
                                <input type="text" id="offVisName" required placeholder="e.g. Kolawole Adeleke" value="${defaults.name || ''}" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.9rem; box-sizing:border-box;">
                            </div>

                            <div style="display:flex; gap:12px; margin-bottom:14px;">
                                <div style="flex:1;">
                                    <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Visitor Phone</label>
                                    <input type="tel" id="offVisPhone" placeholder="080XXXXXXXX" value="${defaults.phone || ''}" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.9rem; box-sizing:border-box;">
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Vehicle Plate (Optional)</label>
                                    <input type="text" id="offVisPlate" placeholder="ABC-123-XY" value="${defaults.plate || ''}" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.9rem; box-sizing:border-box; text-transform:uppercase;">
                                </div>
                            </div>

                            <div style="margin-bottom:14px;">
                                <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Host Resident / Destination Unit *</label>
                                <select id="offVisResident" required style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.88rem; box-sizing:border-box; background:#fff;">
                                    ${residentOptionsHtml}
                                </select>
                            </div>

                            <div style="display:flex; gap:12px; margin-bottom:14px;">
                                <div style="flex:1;">
                                    <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Purpose of Visit</label>
                                    <select id="offVisPurpose" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.88rem; box-sizing:border-box; background:#fff;">
                                        <option value="Personal Guest">Personal Guest</option>
                                        <option value="Package / Food Delivery">Package / Delivery</option>
                                        <option value="Uber / Bolt Taxi">Cab / Taxi Drop-off</option>
                                        <option value="Artisan / Contractor">Artisan / Maintenance</option>
                                        <option value="Official / Business">Official / Business</option>
                                    </select>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Gate</label>
                                    <select id="offVisGate" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.88rem; box-sizing:border-box; background:#fff;">
                                        ${gateOptionsHtml}
                                    </select>
                                </div>
                            </div>

                            <div style="margin-bottom:18px;">
                                <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Officer Notes</label>
                                <input type="text" id="offVisNotes" placeholder="e.g. ID verified, pedestrian entry" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.88rem; box-sizing:border-box;">
                            </div>

                            <div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:12px; border-radius:10px; margin-bottom:18px; font-size:0.8rem; color:#065f46;">
                                <i class="fa-solid fa-circle-check"></i> <strong>Offline Code Guarantee:</strong> Code will be generated instantly and cached locally. When internet returns, it will automatically synchronize to Central Database.
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" id="cancelOffGenBtn" style="background:#f1f5f9; color:#475569; border:none; border-radius:8px; padding:10px 18px; font-weight:600; cursor:pointer;">Cancel</button>
                                <button type="submit" style="background:#2563eb; color:#fff; border:none; border-radius:8px; padding:10px 22px; font-weight:700; cursor:pointer; box-shadow:0 4px 6px -1px rgba(37,99,235,0.4);">
                                    <i class="fa-solid fa-bolt me-1"></i> Generate Pass Code
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;

            document.body.appendChild(div);

            document.getElementById('closeOffGenModal').addEventListener('click', () => div.remove());
            document.getElementById('cancelOffGenBtn').addEventListener('click', () => div.remove());

            document.getElementById('offlineGenForm').addEventListener('submit', (e) => {
                e.preventDefault();
                this.executeOfflineGeneration();
            });
        },

        executeOfflineGeneration: function() {
            const name = document.getElementById('offVisName').value.trim();
            const phone = document.getElementById('offVisPhone').value.trim();
            const plate = document.getElementById('offVisPlate').value.trim().toUpperCase();
            const resSelect = document.getElementById('offVisResident');
            const resOption = resSelect.options[resSelect.selectedIndex];
            const residentId = resSelect.value ? parseInt(resSelect.value) : null;
            const flatId = resOption.getAttribute('data-flat') ? parseInt(resOption.getAttribute('data-flat')) : null;
            const resName = resOption.getAttribute('data-name') || 'Resident';
            const flatNum = resOption.getAttribute('data-flatnum') || 'Unit';
            const purpose = document.getElementById('offVisPurpose').value;
            const gate = document.getElementById('offVisGate').value;
            const notes = document.getElementById('offVisNotes').value.trim();

            if (!name) {
                alert('Please enter visitor name.');
                return;
            }

            // Generate unique offline passcode
            const passcode = this.generatePassCode();
            const nowIso = new Date().toISOString().slice(0, 19).replace('T', ' ');

            // Create record
            const newVisitor = {
                id: 'OFF_' + Date.now(),
                visitor_code: passcode,
                name: name,
                phone: phone,
                resident_id: residentId,
                resident_name: resName,
                flat_id: flatId,
                flat_number: flatNum,
                purpose: purpose,
                entry_gate: gate,
                vehicle_plate: plate,
                guard_notes: notes || 'Created offline at gate',
                status: 'entered',
                entry_time: nowIso,
                created_at: nowIso,
                is_offline_created: true
            };

            // Save in bundle memory & localStorage
            if (!this.bundle) this.bundle = { visitors: [], vehicles: [], residents: [], gates: [] };
            if (!this.bundle.visitors) this.bundle.visitors = [];
            this.bundle.visitors.unshift(newVisitor);
            localStorage.setItem(STORAGE_KEY_BUNDLE, JSON.stringify(this.bundle));

            // Queue for cloud sync
            this.queue.push({
                action: 'create_pass',
                visitor_code: passcode,
                name: name,
                phone: phone,
                resident_id: residentId,
                flat_id: flatId,
                purpose: purpose,
                entry_gate: gate,
                vehicle_plate: plate,
                guard_notes: notes || 'Created offline at gate',
                status: 'entered',
                offline_timestamp: nowIso
            });

            this.saveQueue();

            // Close form modal
            const formModal = document.getElementById('estateOfflineGenModal');
            if (formModal) formModal.remove();

            // Display Official Pass Slip Modal
            this.showPassSlipModal(newVisitor);

            // Trigger sync if online
            if (this.isOnline) {
                this.processSyncQueue();
            }
        },

        showPassSlipModal: function(visitor) {
            let existing = document.getElementById('estatePassSlipModal');
            if (existing) existing.remove();

            const slip = document.createElement('div');
            slip.id = 'estatePassSlipModal';
            slip.innerHTML = `
                <div style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.75); z-index:10002; display:flex; align-items:center; justify-content:center; padding:15px; backdrop-filter:blur(5px);">
                    <div style="background:#fff; width:100%; max-width:440px; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); overflow:hidden; font-family:inherit; text-align:center;">
                        <div style="background:#064e3b; color:#fff; padding:24px 20px 20px; position:relative;">
                            <button type="button" id="closeSlipBtn" style="position:absolute; top:12px; right:15px; background:none; border:none; color:#a7f3d0; font-size:1.5rem; cursor:pointer;">&times;</button>
                            <span style="background:rgba(255,255,255,0.2); color:#a7f3d0; padding:4px 12px; border-radius:9999px; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;">
                                <i class="fa-solid fa-shield-halved"></i> Official Gate Access Pass
                            </span>
                            <div style="margin-top:14px; font-size:2.2rem; font-weight:900; letter-spacing:0.08em; font-family:monospace; color:#34d399;" id="slipCodeDisplay">
                                ${visitor.visitor_code}
                            </div>
                            <small style="color:#a7f3d0; font-size:0.8rem;">Status: CHECKED IN &bull; CLEAR TO ENTER</small>
                        </div>

                        <div style="padding:22px 24px; text-align:left;">
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:18px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                    <span style="color:#64748b; font-size:0.8rem;">Visitor:</span>
                                    <strong style="color:#0f172a; font-size:0.9rem;">${visitor.name}</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                    <span style="color:#64748b; font-size:0.8rem;">Destination:</span>
                                    <strong style="color:#0f172a; font-size:0.9rem;">Flat ${visitor.flat_number || 'Unit'} (${visitor.resident_name || 'Resident'})</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                    <span style="color:#64748b; font-size:0.8rem;">Purpose:</span>
                                    <span style="color:#334155; font-size:0.85rem;">${visitor.purpose}</span>
                                </div>
                                ${visitor.vehicle_plate ? `
                                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                    <span style="color:#64748b; font-size:0.8rem;">Vehicle:</span>
                                    <strong style="font-family:monospace; color:#2563eb; font-size:0.85rem;">${visitor.vehicle_plate}</strong>
                                </div>` : ''}
                                <div style="display:flex; justify-content:space-between;">
                                    <span style="color:#64748b; font-size:0.8rem;">Entry Time:</span>
                                    <span style="color:#64748b; font-size:0.8rem;">${visitor.entry_time}</span>
                                </div>
                            </div>

                            <div style="display:flex; gap:10px;">
                                <button type="button" id="copySlipCodeBtn" style="flex:1; background:#f1f5f9; color:#0f172a; border:none; border-radius:10px; padding:12px; font-weight:700; cursor:pointer; font-size:0.85rem;">
                                    <i class="fa-solid fa-copy me-1"></i> Copy Code
                                </button>
                                <button type="button" id="printSlipBtn" style="flex:1; background:#2563eb; color:#fff; border:none; border-radius:10px; padding:12px; font-weight:700; cursor:pointer; font-size:0.85rem; box-shadow:0 4px 6px -1px rgba(37,99,235,0.4);">
                                    <i class="fa-solid fa-print me-1"></i> Print Pass
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(slip);

            document.getElementById('closeSlipBtn').addEventListener('click', () => slip.remove());

            document.getElementById('copySlipCodeBtn').addEventListener('click', () => {
                navigator.clipboard.writeText(visitor.visitor_code).then(() => {
                    alert(`Passcode ${visitor.visitor_code} copied to clipboard!`);
                });
            });

            document.getElementById('printSlipBtn').addEventListener('click', () => {
                window.print();
            });
        },

        interceptGuardForms: function() {
            // 1. Intercept Check-In form
            const checkInForms = document.querySelectorAll('form');
            checkInForms.forEach(form => {
                const codeInput = form.querySelector('input[name="visitor_code"]');
                const submitBtn = form.querySelector('button[name="process_check_in"]');

                if (codeInput && submitBtn) {
                    form.addEventListener('submit', (e) => {
                        if (!navigator.onLine) {
                            e.preventDefault();
                            this.handleOfflineCheckIn(codeInput.value.trim(), form);
                        }
                    });
                }

                // 2. Intercept Walk-In form (action_type === 'walkin_entry')
                const actionTypeInput = form.querySelector('input[name="action_type"][value="walkin_entry"]');
                if (actionTypeInput) {
                    form.addEventListener('submit', (e) => {
                        if (!navigator.onLine) {
                            e.preventDefault();
                            const fn = form.querySelector('input[name="first_name"]')?.value || '';
                            const ln = form.querySelector('input[name="last_name"]')?.value || '';
                            const ph = form.querySelector('input[name="phone"]')?.value || '';
                            const vp = form.querySelector('input[name="vehicle_plate"]')?.value || '';

                            this.openGenerateModal({
                                name: (fn + ' ' + ln).trim(),
                                phone: ph,
                                plate: vp
                            });
                        }
                    });
                }
            });
        },

        handleOfflineCheckIn: function(rawCode, form) {
            const code = rawCode.toUpperCase();
            if (!code) {
                alert('Please enter a visitor passcode.');
                return;
            }

            if (!this.bundle || !this.bundle.visitors) {
                alert('Offline Gate Storage is empty. Please dial Estate USSD (*384*777#) from your phone to verify this pass via GSM.');
                return;
            }

            // Search offline bundle
            const visitor = this.bundle.visitors.find(v => (v.visitor_code && v.visitor_code.toUpperCase() === code) || String(v.id) === code);

            if (visitor) {
                if (visitor.status === 'entered') {
                    alert(`OFFLINE NOTICE: Visitor '${visitor.name}' was already checked in.`);
                    return;
                }

                // Mark entered in local memory
                visitor.status = 'entered';
                visitor.entry_time = new Date().toISOString().slice(0, 19).replace('T', ' ');

                const gateInput = form.querySelector('select[name="gate_name"]') || form.querySelector('input[name="gate_name"]');
                const plateInput = form.querySelector('input[name="vehicle_plate"]');
                const notesInput = form.querySelector('input[name="guard_notes"]') || form.querySelector('textarea[name="guard_notes"]');

                const gateVal = gateInput ? gateInput.value : 'Main Gate';
                const plateVal = plateInput ? plateInput.value : '';
                const notesVal = notesInput ? notesInput.value : '';

                this.queue.push({
                    action: 'check_in',
                    visitor_code: code,
                    visitor_id: visitor.id,
                    visitor_name: visitor.name,
                    offline_timestamp: visitor.entry_time,
                    entry_gate: gateVal,
                    vehicle_plate: plateVal,
                    guard_notes: notesVal
                });

                this.saveQueue();

                const modalHtml = `
                    <div style="background:#064e3b; color:#ecfdf5; padding:16px; border-radius:12px; margin-bottom:15px; border:1px solid #059669;">
                        <h4 style="margin:0 0 8px 0; font-size:1.1rem; color:#6ee7b7;"><i class="fa-solid fa-circle-check"></i> PASS VERIFIED &amp; CHECKED IN (OFFLINE)</h4>
                        <p style="margin:0 0 4px 0;"><strong>Visitor:</strong> ${visitor.name}</p>
                        <p style="margin:0 0 4px 0;"><strong>Destination:</strong> ${visitor.building_name || ''} Flat ${visitor.flat_number || 'Unit'} (${visitor.resident_name || 'Resident'})</p>
                        <p style="margin:0; font-size:0.8rem; color:#a7f3d0;">Entry recorded in offline queue. Will auto-sync when internet returns.</p>
                    </div>
                `;

                const alertContainer = document.querySelector('.container-fluid') || document.body;
                const wrap = document.createElement('div');
                wrap.innerHTML = modalHtml;
                alertContainer.insertBefore(wrap, alertContainer.firstChild);

                if (form.querySelector('input[name="visitor_code"]')) {
                    form.querySelector('input[name="visitor_code"]').value = '';
                }

                this.showToast('success', 'Checked In (Offline)', `Visitor ${visitor.name} verified from local gate storage.`);
            } else {
                alert(`PASSCODE NOT FOUND IN LOCAL CACHE!\n\nPasscode: ${code}\nBecause the gate is currently offline, this pass might have been created recently.\n\nACTION: Dial the Estate USSD shortcode (*384*777#) from your mobile phone, or click '+ New Pass Code' to issue a new offline code!`);
            }
        },

        processSyncQueue: function(manual = false) {
            if (!this.isOnline || this.queue.length === 0) return;

            const syncBtn = document.getElementById('offlineSyncBtn');
            if (syncBtn) {
                const icon = syncBtn.querySelector('i');
                if (icon) icon.className = 'fa-solid fa-arrows-rotate sync-spin';
            }

            const payload = {
                terminal_id: 'gate_terminal_browser',
                events: this.queue
            };

            fetch(SYNC_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (syncBtn) {
                    const icon = syncBtn.querySelector('i');
                    if (icon) icon.className = 'fa-solid fa-arrows-rotate';
                }

                if (data.status === 'success') {
                    const count = data.synced_count;
                    this.queue = [];
                    this.saveQueue();
                    this.downloadFreshBundle();

                    this.showToast('success', 'Sync Successful', `${count} offline check-ins synchronized with Central Estate Cloud!`);
                    if (manual) {
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } else {
                    this.showToast('error', 'Sync Warning', data.message || 'Could not sync queue.');
                }
            })
            .catch(err => {
                if (syncBtn) {
                    const icon = syncBtn.querySelector('i');
                    if (icon) icon.className = 'fa-solid fa-arrows-rotate';
                }
                console.warn('[OfflineSync] Sync failed:', err);
            });
        },

        showToast: function(type, title, msg) {
            if (window.EstateNotification && typeof window.EstateNotification.toast === 'function') {
                window.EstateNotification.toast(type, `${title}: ${msg}`);
                return;
            }

            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed; top: 24px; right: 24px; z-index: 10000;
                background: ${type === 'success' ? '#065f46' : (type === 'warning' ? '#92400e' : '#1e293b')};
                color: #fff; padding: 12px 20px; border-radius: 10px; font-size: 0.85rem;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); max-width: 320px; line-height: 1.4;
                transition: opacity 0.5s ease;
            `;
            toast.innerHTML = `<strong>${title}</strong><br>${msg}`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 500);
            }, 4500);
        }
    };

    // Auto-init on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.EstateOfflineSync.init());
    } else {
        window.EstateOfflineSync.init();
    }
})();
