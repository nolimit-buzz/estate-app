/**
 * js/emergency_alarm.js
 * Multi-Panic Red Alarm Bar, Real-time Security Siren Synthesizer & Instant Silencing Engine
 */

(function () {
    'use strict';

    // State
    let audioCtx = null;
    let sirenOsc = null;
    let sirenGain = null;
    let sirenInterval = null;
    let isSirenPlaying = false;
    let isMuted = localStorage.getItem('estate_siren_muted') === 'true';
    let isBannerDismissed = false;
    let activeAlerts = [];
    let currentAlertIndex = 0;
    let autoRotateTimer = null;
    let pollTimer = null;

    // Web Audio Synthesizer: Authentic Dual-Tone Emergency Siren
    function initAudio() {
        if (!audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                audioCtx = new AudioContext();
            }
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    }

    function startSirenSound() {
        if (isSirenPlaying || isMuted) return;
        try {
            initAudio();
            if (!audioCtx) return;

            sirenOsc = audioCtx.createOscillator();
            sirenGain = audioCtx.createGain();

            sirenOsc.type = 'sawtooth';
            sirenGain.gain.setValueAtTime(0.25, audioCtx.currentTime);

            // Oscillate frequency between 750Hz and 1150Hz for realistic emergency klaxon
            let high = false;
            sirenOsc.frequency.setValueAtTime(800, audioCtx.currentTime);
            
            sirenInterval = setInterval(() => {
                if (!audioCtx || !sirenOsc) return;
                const now = audioCtx.currentTime;
                const targetFreq = high ? 750 : 1150;
                sirenOsc.frequency.exponentialRampToValueAtTime(targetFreq, now + 0.35);
                high = !high;
            }, 450);

            sirenOsc.connect(sirenGain);
            sirenGain.connect(audioCtx.destination);
            sirenOsc.start();
            isSirenPlaying = true;
        } catch (e) {
            console.warn('Emergency siren audio could not autoplay:', e);
        }
    }

    function stopSirenSound() {
        if (sirenInterval) {
            clearInterval(sirenInterval);
            sirenInterval = null;
        }
        if (sirenOsc) {
            try {
                sirenOsc.stop();
                sirenOsc.disconnect();
            } catch (e) {}
            sirenOsc = null;
        }
        if (sirenGain) {
            try {
                sirenGain.disconnect();
            } catch (e) {}
            sirenGain = null;
        }
        isSirenPlaying = false;
    }

    // Toggle local mute state
    window.toggleEstateSirenMute = function () {
        isMuted = !isMuted;
        localStorage.setItem('estate_siren_muted', isMuted ? 'true' : 'false');
        if (isMuted) {
            stopSirenSound();
        } else {
            // If active alarms exist with sound_alarm == 1, resume siren
            const shouldPlay = activeAlerts.some(a => a.sound_alarm == 1 && a.status === 'active');
            if (shouldPlay) startSirenSound();
        }
        updateMuteButtonUI();
    };

    function updateMuteButtonUI() {
        const btn = document.getElementById('estate-alarm-mute-btn');
        if (btn) {
            btn.innerHTML = isMuted 
                ? '<i class="fa-solid fa-volume-xmark me-1"></i> Unmute Siren' 
                : '<i class="fa-solid fa-volume-high me-1"></i> Mute Siren';
            btn.className = isMuted 
                ? 'btn btn-sm btn-outline-light rounded-pill px-3' 
                : 'btn btn-sm btn-light text-danger fw-bold rounded-pill px-3';
        }
    }

    // Dismiss banner locally for current session
    window.dismissEmergencyBanner = function () {
        isBannerDismissed = true;
        const banner = document.getElementById('estate-emergency-banner');
        if (banner) banner.style.display = 'none';
        document.body.style.paddingTop = '0px';
    };

    // Silence siren estate-wide (Admin/Staff/Initiator)
    window.silenceEmergencySiren = function (alertId) {
        const apiPath = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/resident/') || window.location.pathname.includes('/staff/') || window.location.pathname.includes('/zone/')
            ? '../api/emergency.php'
            : 'api/emergency.php';

        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=silence_alarm&alert_id=${encodeURIComponent(alertId)}`
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                stopSirenSound();
                pollActiveAlerts();
                if (window.EstateDialog) {
                    EstateDialog.toast({ type: 'success', title: 'Siren Silenced', message: 'Audio sirens muted estate-wide.' });
                }
            } else {
                if (window.EstateDialog) {
                    EstateDialog.toast({ type: 'error', title: 'Error', message: res.error || 'Failed to silence siren' });
                }
            }
        })
        .catch(err => console.error(err));
    };

    // Quick Acknowledge for Staff / Admins
    window.quickAcknowledgeEmergency = function (alertId) {
        const apiPath = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/resident/') || window.location.pathname.includes('/staff/') || window.location.pathname.includes('/zone/')
            ? '../api/emergency.php'
            : 'api/emergency.php';

        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=acknowledge_alert&alert_id=${encodeURIComponent(alertId)}&status=dispatched`
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                stopSirenSound();
                pollActiveAlerts();
                if (window.EstateDialog) {
                    EstateDialog.toast({ type: 'success', title: 'Incident Acknowledged', message: 'Response teams notified.' });
                }
            } else {
                if (window.EstateDialog) {
                    EstateDialog.toast({ type: 'error', title: 'Error', message: res.error || 'Failed to acknowledge incident' });
                }
            }
        })
        .catch(err => console.error(err));
    };

    // Quick Cancel False Alarm by Initiator
    window.quickCancelOwnEmergency = function (alertId) {
        const doCancel = () => {
            const apiPath = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/resident/') || window.location.pathname.includes('/staff/') || window.location.pathname.includes('/zone/')
                ? '../api/emergency.php'
                : 'api/emergency.php';

            fetch(apiPath, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=resolve_alert&alert_id=${encodeURIComponent(alertId)}&status=false_alarm&resolution_action=Verified False Alarm - Resident Safe&resolution_notes=Initiator canceled alert`
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    stopSirenSound();
                    pollActiveAlerts();
                    if (window.EstateDialog) {
                        EstateDialog.toast({ type: 'info', title: 'Alert Cancelled', message: 'Emergency alert marked as false alarm and cancelled.' });
                    }
                } else {
                    if (window.EstateDialog) {
                        EstateDialog.toast({ type: 'error', title: 'Cancellation Error', message: res.error || 'Failed to cancel alert' });
                    }
                }
            })
            .catch(err => console.error(err));
        };

        if (window.EstateDialog) {
            EstateDialog.confirm({
                title: 'Cancel Emergency Alert?',
                message: 'Are you sure you want to cancel this emergency alert? This will mark the incident as a false alarm and notify the control room.',
                type: 'warning',
                confirmText: 'Yes, Cancel SOS',
                cancelText: 'Keep Active'
            }).then(confirmed => {
                if (confirmed) doCancel();
            });
        } else {
            doCancel();
        }
    };

    // Carousel Navigation
    window.navigateEmergencyAlert = function (direction) {
        if (activeAlerts.length <= 1) return;
        currentAlertIndex += direction;
        if (currentAlertIndex < 0) currentAlertIndex = activeAlerts.length - 1;
        if (currentAlertIndex >= activeAlerts.length) currentAlertIndex = 0;
        renderCurrentAlert();
    };

    // Inject Emergency Flashing Top Banner into DOM
    function ensureEmergencyBanner() {
        let banner = document.getElementById('estate-emergency-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'estate-emergency-banner';
            banner.style.cssText = `
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 999999;
                background: linear-gradient(90deg, #991b1b, #dc2626, #b91c1c);
                color: #ffffff;
                box-shadow: 0 4px 25px rgba(185, 28, 28, 0.7);
                border-bottom: 3px solid #fef08a;
                font-family: 'Outfit', sans-serif;
                animation: emergencyPulse 1.4s infinite alternate ease-in-out;
            `;
            
            // Add keyframe style
            if (!document.getElementById('emergency-pulse-style')) {
                const style = document.createElement('style');
                style.id = 'emergency-pulse-style';
                style.innerHTML = `
                    @keyframes emergencyPulse {
                        0% { background: linear-gradient(90deg, #991b1b, #dc2626, #991b1b); box-shadow: 0 4px 15px rgba(220, 38, 38, 0.4); }
                        100% { background: linear-gradient(90deg, #7f1d1d, #b91c1c, #7f1d1d); box-shadow: 0 6px 28px rgba(239, 68, 68, 0.95); }
                    }
                    .emergency-beacon {
                        display: inline-block;
                        width: 12px;
                        height: 12px;
                        border-radius: 50%;
                        background: #fef08a;
                        box-shadow: 0 0 10px #fef08a;
                        animation: beaconBlink 0.6s infinite alternate;
                    }
                    @keyframes beaconBlink {
                        0% { opacity: 0.2; transform: scale(0.85); }
                        100% { opacity: 1; transform: scale(1.2); }
                    }
                `;
                document.head.appendChild(style);
            }

            document.body.prepend(banner);
        }
        return banner;
    }

    // Render Current Alert Card Inside Banner
    function renderCurrentAlert() {
        const banner = ensureEmergencyBanner();
        if (activeAlerts.length === 0 || isBannerDismissed) {
            banner.style.display = 'none';
            document.body.style.paddingTop = '0px';
            stopSirenSound();
            return;
        }

        if (currentAlertIndex >= activeAlerts.length) currentAlertIndex = 0;
        const alert = activeAlerts[currentAlertIndex];
        const isBroadcast = (alert.sender_type === 'central_admin' || alert.sender_type === 'zone_admin');
        const locationText = alert.building_name 
            ? `${alert.building_name} • Unit ${alert.flat_number || ''}` 
            : (isBroadcast ? 'Estate Command Advisory' : 'Estate Grounds');
        const totalCount = activeAlerts.length;

        banner.innerHTML = `
            <div class="container-fluid py-2 px-3 px-md-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <!-- Left: Counter & Carousel Indicators -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="emergency-beacon"></span>
                        
                        ${totalCount > 1 ? `
                            <span class="badge bg-warning text-dark fw-bold px-2 py-1" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> ${totalCount} ACTIVE EMERGENCIES
                            </span>
                            <div class="btn-group btn-group-sm ms-1" role="group">
                                <button type="button" onclick="navigateEmergencyAlert(-1)" class="btn btn-xs btn-dark rounded-start-pill text-white px-2 py-0" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>
                                <span class="btn btn-xs btn-dark disabled text-warning fw-bold px-2 py-0 border-0" style="font-size: 0.72rem;">
                                    ${currentAlertIndex + 1} of ${totalCount}
                                </span>
                                <button type="button" onclick="navigateEmergencyAlert(1)" class="btn btn-xs btn-dark rounded-end-pill text-white px-2 py-0" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        ` : `
                            <span class="badge bg-warning text-dark fw-bold px-2 py-1" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> ACTIVE EMERGENCY ALERT
                            </span>
                        `}

                        <strong class="text-white fs-6 mb-0 ms-1">${escapeHtml(alert.category_name)}</strong>
                        <span class="badge bg-black bg-opacity-30 text-white font-monospace" style="font-size: 0.7rem;">${escapeHtml(alert.alert_code)}</span>
                        <span class="text-white-50 d-none d-md-inline">&bull;</span>
                        <span class="text-white-75 small d-none d-md-inline"><i class="fa-solid fa-location-dot me-1 text-warning"></i> ${locationText}</span>
                    </div>

                    <!-- Right: Action Controls (Call, Silence, Mute, Dismiss) -->
                    <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
                        ${alert.sender_phone ? `
                            <a href="tel:${alert.sender_phone}" class="btn btn-sm btn-light rounded-pill px-3 d-none d-sm-inline-flex align-items-center text-dark fw-semibold" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-phone me-1 text-success"></i> Call ${escapeHtml(alert.sender_name)}
                            </a>
                        ` : ''}

                        <!-- Silence Siren Estate-Wide (Admin / Staff / Initiator) -->
                        ${(window.ESTATE_IS_STAFF_OR_ADMIN || alert.is_own_alert) && alert.sound_alarm == 1 ? `
                            <button type="button" onclick="silenceEmergencySiren(${alert.id})" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-bell-slash me-1"></i> Silence Siren
                            </button>
                        ` : ''}

                        <!-- Local Mute -->
                        <button type="button" id="estate-alarm-mute-btn" onclick="toggleEstateSirenMute()" class="btn btn-sm btn-light text-danger fw-bold rounded-pill px-3" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-volume-high me-1"></i> Mute
                        </button>

                        <!-- Initiator False Alarm Cancel -->
                        ${alert.is_own_alert ? `
                            <button type="button" onclick="quickCancelOwnEmergency(${alert.id})" class="btn btn-sm btn-dark text-white rounded-pill px-3" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-xmark me-1 text-danger"></i> Cancel SOS
                            </button>
                        ` : ''}

                        <!-- Admin / Staff Dispatch Shortcut -->
                        ${window.ESTATE_IS_STAFF_OR_ADMIN && alert.status === 'active' ? `
                            <button type="button" onclick="quickAcknowledgeEmergency(${alert.id})" class="btn btn-sm btn-info text-dark fw-bold rounded-pill px-3" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-person-running me-1"></i> Dispatch
                            </button>
                        ` : ''}

                        <a href="${window.ESTATE_EMERGENCY_URL || '../resident/emergency'}" class="btn btn-sm btn-outline-light rounded-pill px-3" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View
                        </a>

                        <button type="button" onclick="dismissEmergencyBanner()" class="btn btn-sm btn-link text-white-50 p-1" title="Hide banner">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>
            ${alert.headline ? `
                <div class="bg-black bg-opacity-25 px-4 py-1 small text-center text-white-75 border-top border-white border-opacity-10">
                    <i class="fa-solid fa-bullhorn me-1 text-warning"></i> <strong>Advisory:</strong> ${escapeHtml(alert.headline)} 
                    ${alert.note ? `— ${escapeHtml(alert.note)}` : ''}
                </div>
            ` : ''}
        `;

        banner.style.display = 'block';
        document.body.style.paddingTop = banner.offsetHeight + 'px';
        updateMuteButtonUI();
    }

    // Process Active Alerts from Polling
    function processActiveAlerts(data) {
        if (!data || !data.has_active || !data.alerts || data.alerts.length === 0) {
            activeAlerts = [];
            currentAlertIndex = 0;
            const banner = document.getElementById('estate-emergency-banner');
            if (banner) banner.style.display = 'none';
            document.body.style.paddingTop = '0px';
            stopSirenSound();
            if (autoRotateTimer) {
                clearInterval(autoRotateTimer);
                autoRotateTimer = null;
            }
            return;
        }

        activeAlerts = data.alerts;
        renderCurrentAlert();

        // Sound Siren if any active alert has sound_alarm = 1 and not muted
        if (data.should_sound_alarm && !isMuted) {
            startSirenSound();
        } else {
            stopSirenSound();
        }

        // Setup Auto-Rotate Carousel when > 1 alert exists
        if (activeAlerts.length > 1 && !autoRotateTimer) {
            autoRotateTimer = setInterval(() => {
                navigateEmergencyAlert(1);
            }, 7500);
        } else if (activeAlerts.length <= 1 && autoRotateTimer) {
            clearInterval(autoRotateTimer);
            autoRotateTimer = null;
        }
    }

    // Poll API for Active Alerts
    function pollActiveAlerts() {
        const apiPath = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/resident/') || window.location.pathname.includes('/staff/') || window.location.pathname.includes('/zone/')
            ? '../api/emergency.php?action=check_active_alerts'
            : 'api/emergency.php?action=check_active_alerts';

        fetch(apiPath)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    processActiveAlerts(data);
                }
            })
            .catch(err => console.debug('Emergency polling retry:', err));
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Start Polling on Page Load
    document.addEventListener('DOMContentLoaded', () => {
        // Initial check
        pollActiveAlerts();
        // Recurring 5s poll
        pollTimer = setInterval(pollActiveAlerts, 5000);

        // Resume AudioContext on any first user interaction (browser policy)
        const armAudioOnTouch = () => {
            initAudio();
            document.removeEventListener('click', armAudioOnTouch);
            document.removeEventListener('keydown', armAudioOnTouch);
        };
        document.addEventListener('click', armAudioOnTouch);
        document.addEventListener('keydown', armAudioOnTouch);
    });

})();
