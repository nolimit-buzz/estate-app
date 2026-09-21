/**
 * js/anti_duplicate.js - Universal Anti-Duplicate & Single-Click Submission Engine
 * Enforces "just one click" across all forms, prevents double-clicks, and stops duplicate
 * records caused by browser refreshing (F5), double-submitting, or rapid clicks.
 */
(function() {
    'use strict';

    // Helper: Generate a collision-resistant unique form token
    function generateToken() {
        return 'tok_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 12);
    }

    // Helper: Ensure a form has a fresh unique __form_token hidden field
    function ensureFormToken(form) {
        if (!form || form.method.toUpperCase() !== 'POST') return;
        let tokenInput = form.querySelector('input[name="__form_token"]');
        if (!tokenInput) {
            tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = '__form_token';
            tokenInput.value = generateToken();
            form.prepend(tokenInput);
        } else if (!tokenInput.value) {
            tokenInput.value = generateToken();
        }
    }

    // Initialize all existing POST forms on DOM ready
    function initForms() {
        document.querySelectorAll('form[method="POST" i], form[method="post" i]').forEach(ensureFormToken);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initForms);
    } else {
        initForms();
    }

    // Track active clicked button for submitter identification
    let lastClickedSubmitBtn = null;
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('button, input[type="submit"]');
        if (!btn) return;
        
        // If it belongs to a form and is a submit type
        const form = btn.closest('form');
        if (form && (btn.type === 'submit' || !btn.type || btn.tagName === 'BUTTON')) {
            lastClickedSubmitBtn = btn;
        }

        // Rapid click debouncing on action buttons (e.g., delete/archive modals, action links)
        if (btn.classList.contains('btn-action') || btn.hasAttribute('data-oneclick')) {
            if (btn.dataset.clicked === 'true') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
            btn.dataset.clicked = 'true';
            setTimeout(function() {
                btn.dataset.clicked = 'false';
            }, 1800);
        }
    }, true);

    // Global Form Submit Interceptor
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.method.toUpperCase() !== 'POST') return;

        // 1. If form is already currently submitting, HARD BLOCK subsequent submits
        if (form.dataset.submitting === 'true') {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }

        // 2. Validate HTML5 constraints if present
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            // Let the browser display native invalid field tooltips without locking
            return;
        }

        // 3. Ensure a valid one-time token exists in the form
        ensureFormToken(form);

        // 4. Mark form as submitting
        form.dataset.submitting = 'true';

        // 5. Identify the submitter button
        const submitBtn = e.submitter || lastClickedSubmitBtn || form.querySelector('button[type="submit"], input[type="submit"]');

        if (submitBtn) {
            // Preserve button's name & value in the POST payload in case button disabling drops it
            if (submitBtn.name && !form.querySelector(`input[type="hidden"][name="${CSS.escape(submitBtn.name)}"]`)) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = submitBtn.name;
                hiddenInput.value = submitBtn.value || '1';
                form.appendChild(hiddenInput);
            }

            // Save original button content
            if (!submitBtn.hasAttribute('data-original-html')) {
                submitBtn.setAttribute('data-original-html', submitBtn.innerHTML);
            }

            // Immediately disable pointer events & show elegant loading spinner
            submitBtn.classList.add('btn-loading');
            submitBtn.style.pointerEvents = 'none';

            // Custom or default loading text
            const customLoadingText = submitBtn.getAttribute('data-loading-text') || 'Processing...';
            submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="width: 0.9rem; height: 0.9rem; vertical-align: -0.15em; border-width: 0.15em;"></span> ${customLoadingText}`;

            // Disable all submit buttons on next microtask so the browser finishes packaging the form
            setTimeout(function() {
                const allSubmitBtns = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])');
                allSubmitBtns.forEach(function(b) {
                    b.disabled = true;
                    b.style.pointerEvents = 'none';
                });
            }, 30);

            // Safety timeout: If navigation hasn't completed in 15s (e.g. slow network or cancelled), unlock
            setTimeout(function() {
                resetFormSubmission(form);
            }, 15000);
        }
    }, true);

    // Reset helper to restore button states (used on safety timeout or bfcache restore)
    function resetFormSubmission(form) {
        if (!form) return;
        form.dataset.submitting = 'false';
        
        // Regenerate fresh token so subsequent submission is valid
        const tokenInput = form.querySelector('input[name="__form_token"]');
        if (tokenInput) tokenInput.value = generateToken();

        const allSubmitBtns = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type]), .btn-loading');
        allSubmitBtns.forEach(function(btn) {
            btn.disabled = false;
            btn.style.pointerEvents = '';
            btn.classList.remove('btn-loading');
            if (btn.hasAttribute('data-original-html')) {
                btn.innerHTML = btn.getAttribute('data-original-html');
            }
        });
    }

    // Handle BFCache (when user presses Back or Forward in browser)
    window.addEventListener('pageshow', function(event) {
        document.querySelectorAll('form').forEach(resetFormSubmission);
    });

})();
