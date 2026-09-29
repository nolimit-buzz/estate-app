/**
 * Estate Management Platform - Mobile Application Engine
 * Delivers native iOS/Android interaction paradigms on mobile & tablet devices
 */

(function() {
    'use strict';

    // Global Mobile Action Sheet Controls
    window.openMobileActionSheet = function() {
        const backdrop = document.getElementById('mobileActionSheetBackdrop');
        if (backdrop) {
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeMobileActionSheet = function() {
        const backdrop = document.getElementById('mobileActionSheetBackdrop');
        if (backdrop) {
            backdrop.classList.remove('active');
            document.body.style.overflow = '';
        }
    };

    // Initialize on DOM Ready
    document.addEventListener('DOMContentLoaded', function() {
        initMobileSheetEvents();
        initMobileBackButton();
        initMobileMonthlyToggle();
        ensureMobileNavSafeAreas();
    });

    // 1. Action Sheet Event Handlers
    function initMobileSheetEvents() {
        // Trigger buttons
        document.querySelectorAll('.btn-plus-accent, [data-open-mobile-sheet]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                window.openMobileActionSheet();
            });
        });

        // Close button
        const closeBtn = document.getElementById('mobileSheetCloseBtn');
        if (closeBtn) {
            closeBtn.addEventListener('click', window.closeMobileActionSheet);
        }

        // Backdrop click to dismiss
        const backdrop = document.getElementById('mobileActionSheetBackdrop');
        if (backdrop) {
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) {
                    window.closeMobileActionSheet();
                }
            });
        }

        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.closeMobileActionSheet();
            }
        });
    }

    // 2. Native Sub-page Back Navigation
    function initMobileBackButton() {
        document.querySelectorAll('.mobile-subpage-back').forEach(btn => {
            btn.addEventListener('click', function(e) {
                // If it has a specific href like "index", fallback gracefully if no referrer
                if (window.history.length > 1 && document.referrer && document.referrer.includes(window.location.host)) {
                    e.preventDefault();
                    window.history.back();
                }
            });
        });
    }

    // 3. Monthly / Period Filter Dropdown for Mobile Bar Chart
    function initMobileMonthlyToggle() {
        document.querySelectorAll('.mobile-pill-dropdown').forEach(pill => {
            pill.addEventListener('click', function() {
                const currentText = this.innerText.trim();
                if (currentText.includes('Monthly')) {
                    this.innerHTML = '<span>Quarterly</span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 0.65rem;"></i>';
                } else if (currentText.includes('Quarterly')) {
                    this.innerHTML = '<span>Yearly</span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 0.65rem;"></i>';
                } else {
                    this.innerHTML = '<span>Monthly</span> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 0.65rem;"></i>';
                }
            });
        });
    }

    // 4. Safe Area & Mobile Body Offsets
    function ensureMobileNavSafeAreas() {
        function checkViewport() {
            if (window.innerWidth <= 991) {
                const nav = document.querySelector('.mobile-bottom-nav');
                if (nav) {
                    const navHeight = nav.offsetHeight || 66;
                    document.body.style.paddingBottom = (navHeight + 20) + 'px';
                }
            } else {
                document.body.style.paddingBottom = '';
            }
        }
        checkViewport();
        window.addEventListener('resize', checkViewport);
    }
})();
