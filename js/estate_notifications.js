/**
 * Estate In-House Notification, Alert Modal, Confirmation Dialog & Toast Engine
 * Replaces all native browser dialogs (alert, confirm, prompt) with custom styled UI.
 */
(function(window, document) {
    'use strict';

    // Container references
    let dialogBackdrop = null;
    let toastContainer = null;

    /**
     * Initialize DOM containers
     */
    function initContainers() {
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', initContainers);
            return;
        }

        // 1. Modal Backdrop
        if (!document.getElementById('estate-dialog-backdrop')) {
            dialogBackdrop = document.createElement('div');
            dialogBackdrop.id = 'estate-dialog-backdrop';
            dialogBackdrop.className = 'estate-dialog-backdrop';
            dialogBackdrop.innerHTML = `
                <div class="estate-dialog-box" id="estate-dialog-box">
                    <div class="estate-dialog-header">
                        <div class="estate-dialog-icon danger" id="estate-dialog-icon">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <h4 class="estate-dialog-title" id="estate-dialog-title">Confirm Action</h4>
                        <p class="estate-dialog-message" id="estate-dialog-message">Are you sure you want to proceed?</p>
                    </div>
                    <div class="estate-dialog-body" id="estate-dialog-body" style="display: none;">
                        <input type="text" class="estate-dialog-input" id="estate-dialog-input" autocomplete="off" />
                        <textarea class="estate-dialog-textarea" id="estate-dialog-textarea" rows="3" style="display: none;"></textarea>
                    </div>
                    <div class="estate-dialog-footer" id="estate-dialog-footer">
                        <button type="button" class="estate-dialog-btn estate-dialog-btn-cancel" id="estate-dialog-cancel-btn">Cancel</button>
                        <button type="button" class="estate-dialog-btn estate-dialog-btn-confirm" id="estate-dialog-confirm-btn">Confirm</button>
                    </div>
                </div>
            `;
            document.body.appendChild(dialogBackdrop);
        } else {
            dialogBackdrop = document.getElementById('estate-dialog-backdrop');
        }

        // 2. Toast Container
        if (!document.getElementById('estate-toast-container')) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'estate-toast-container';
            toastContainer.className = 'estate-toast-container';
            document.body.appendChild(toastContainer);
        } else {
            toastContainer = document.getElementById('estate-toast-container');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initContainers);
    } else {
        initContainers();
    }

    const EstateDialog = {
        /**
         * Show custom in-house Confirmation Modal
         * Returns a Promise resolving to true (confirm) or false (cancel)
         */
        confirm: function(options) {
            initContainers();
            return new Promise((resolve) => {
                const title = (typeof options === 'object' && options.title) ? options.title : 'Confirm Action';
                const message = typeof options === 'string' ? options : (options.message || 'Are you sure you want to proceed?');
                const type = (typeof options === 'object' && options.type) ? options.type : 'danger'; // 'danger', 'warning', 'info', 'success', 'primary'
                const confirmText = (typeof options === 'object' && options.confirmText) ? options.confirmText : 'Confirm';
                const cancelText = (typeof options === 'object' && options.cancelText) ? options.cancelText : 'Cancel';

                const iconEl = document.getElementById('estate-dialog-icon');
                const titleEl = document.getElementById('estate-dialog-title');
                const msgEl = document.getElementById('estate-dialog-message');
                const bodyEl = document.getElementById('estate-dialog-body');
                const cancelBtn = document.getElementById('estate-dialog-cancel-btn');
                const confirmBtn = document.getElementById('estate-dialog-confirm-btn');

                if (bodyEl) bodyEl.style.display = 'none';

                // Configure Icon & Type
                iconEl.className = 'estate-dialog-icon ' + type;
                let faIcon = 'fa-triangle-exclamation';
                if (type === 'warning') faIcon = 'fa-circle-exclamation';
                else if (type === 'info') faIcon = 'fa-circle-info';
                else if (type === 'success') faIcon = 'fa-circle-check';
                else if (type === 'primary') faIcon = 'fa-circle-question';
                iconEl.innerHTML = `<i class="fa-solid ${faIcon}"></i>`;

                titleEl.textContent = title;
                msgEl.innerHTML = typeof message === 'string' ? message.replace(/\n/g, '<br>') : message;

                cancelBtn.textContent = cancelText;
                cancelBtn.style.display = 'inline-flex';

                let btnClass = 'estate-dialog-btn-danger';
                if (type === 'warning') btnClass = 'estate-dialog-btn-warning';
                else if (type === 'success') btnClass = 'estate-dialog-btn-success';
                else if (type === 'primary' || type === 'info') btnClass = 'estate-dialog-btn-primary';

                confirmBtn.className = `estate-dialog-btn ${btnClass}`;
                confirmBtn.innerHTML = `${confirmText}`;

                // Show modal
                dialogBackdrop.classList.add('active');

                // Focus confirm button
                setTimeout(() => confirmBtn.focus(), 50);

                function cleanup(result) {
                    dialogBackdrop.classList.remove('active');
                    cancelBtn.removeEventListener('click', onCancel);
                    confirmBtn.removeEventListener('click', onConfirm);
                    dialogBackdrop.removeEventListener('click', onBackdrop);
                    document.removeEventListener('keydown', onKey);
                    resolve(result);
                }

                function onCancel() { cleanup(false); }
                function onConfirm() { cleanup(true); }
                function onBackdrop(e) { if (e.target === dialogBackdrop) cleanup(false); }
                function onKey(e) {
                    if (e.key === 'Escape') cleanup(false);
                    if (e.key === 'Enter') cleanup(true);
                }

                cancelBtn.addEventListener('click', onCancel);
                confirmBtn.addEventListener('click', onConfirm);
                dialogBackdrop.addEventListener('click', onBackdrop);
                document.addEventListener('keydown', onKey);
            });
        },

        /**
         * Show custom in-house Alert Modal
         */
        alert: function(options) {
            initContainers();
            return new Promise((resolve) => {
                const title = (typeof options === 'object' && options.title) ? options.title : 'Notice';
                const message = typeof options === 'string' ? options : (options.message || '');
                const type = (typeof options === 'object' && options.type) ? options.type : 'info';
                const buttonText = (typeof options === 'object' && options.buttonText) ? options.buttonText : 'OK';

                const iconEl = document.getElementById('estate-dialog-icon');
                const titleEl = document.getElementById('estate-dialog-title');
                const msgEl = document.getElementById('estate-dialog-message');
                const bodyEl = document.getElementById('estate-dialog-body');
                const cancelBtn = document.getElementById('estate-dialog-cancel-btn');
                const confirmBtn = document.getElementById('estate-dialog-confirm-btn');

                if (bodyEl) bodyEl.style.display = 'none';

                iconEl.className = 'estate-dialog-icon ' + type;
                let faIcon = 'fa-circle-info';
                if (type === 'danger' || type === 'error') faIcon = 'fa-triangle-exclamation';
                else if (type === 'warning') faIcon = 'fa-circle-exclamation';
                else if (type === 'success') faIcon = 'fa-circle-check';
                iconEl.innerHTML = `<i class="fa-solid ${faIcon}"></i>`;

                titleEl.textContent = title;
                msgEl.innerHTML = typeof message === 'string' ? message.replace(/\n/g, '<br>') : message;

                cancelBtn.style.display = 'none'; // Hide cancel button for alert
                
                let btnClass = 'estate-dialog-btn-primary';
                if (type === 'danger' || type === 'error') btnClass = 'estate-dialog-btn-danger';
                else if (type === 'warning') btnClass = 'estate-dialog-btn-warning';
                else if (type === 'success') btnClass = 'estate-dialog-btn-success';

                confirmBtn.className = `estate-dialog-btn ${btnClass}`;
                confirmBtn.textContent = buttonText;

                dialogBackdrop.classList.add('active');
                setTimeout(() => confirmBtn.focus(), 50);

                function cleanup() {
                    dialogBackdrop.classList.remove('active');
                    confirmBtn.removeEventListener('click', onConfirm);
                    dialogBackdrop.removeEventListener('click', onBackdrop);
                    document.removeEventListener('keydown', onKey);
                    resolve(true);
                }

                function onConfirm() { cleanup(); }
                function onBackdrop(e) { if (e.target === dialogBackdrop) cleanup(); }
                function onKey(e) { if (e.key === 'Escape' || e.key === 'Enter') cleanup(); }

                confirmBtn.addEventListener('click', onConfirm);
                dialogBackdrop.addEventListener('click', onBackdrop);
                document.addEventListener('keydown', onKey);
            });
        },

        /**
         * Show custom in-house Prompt Input Modal
         * Returns a Promise resolving to the input value (string) or null if cancelled
         */
        prompt: function(options) {
            initContainers();
            return new Promise((resolve) => {
                let message = 'Please enter a value:';
                let defaultValue = '';
                let title = 'Input Required';
                let placeholder = '';
                let inputType = 'text'; // 'text', 'textarea', 'date', 'number'
                let confirmText = 'OK';
                let cancelText = 'Cancel';

                if (typeof options === 'string') {
                    message = options;
                    if (arguments.length > 1 && typeof arguments[1] === 'string') {
                        defaultValue = arguments[1];
                    }
                } else if (typeof options === 'object') {
                    title = options.title || 'Input Required';
                    message = options.message || options.text || 'Please enter a value:';
                    defaultValue = options.defaultValue || options.value || '';
                    placeholder = options.placeholder || '';
                    inputType = options.inputType || 'text';
                    confirmText = options.confirmText || 'OK';
                    cancelText = options.cancelText || 'Cancel';
                }

                const iconEl = document.getElementById('estate-dialog-icon');
                const titleEl = document.getElementById('estate-dialog-title');
                const msgEl = document.getElementById('estate-dialog-message');
                const bodyEl = document.getElementById('estate-dialog-body');
                const inputEl = document.getElementById('estate-dialog-input');
                const textareaEl = document.getElementById('estate-dialog-textarea');
                const cancelBtn = document.getElementById('estate-dialog-cancel-btn');
                const confirmBtn = document.getElementById('estate-dialog-confirm-btn');

                iconEl.className = 'estate-dialog-icon prompt';
                iconEl.innerHTML = `<i class="fa-solid fa-pen-to-square"></i>`;

                titleEl.textContent = title;
                msgEl.innerHTML = typeof message === 'string' ? message.replace(/\n/g, '<br>') : message;

                bodyEl.style.display = 'block';

                let activeField = inputEl;
                if (inputType === 'textarea') {
                    inputEl.style.display = 'none';
                    textareaEl.style.display = 'block';
                    textareaEl.value = defaultValue;
                    textareaEl.placeholder = placeholder;
                    activeField = textareaEl;
                } else {
                    textareaEl.style.display = 'none';
                    inputEl.style.display = 'block';
                    inputEl.type = inputType || 'text';
                    inputEl.value = defaultValue;
                    inputEl.placeholder = placeholder;
                    activeField = inputEl;
                }

                cancelBtn.textContent = cancelText;
                cancelBtn.style.display = 'inline-flex';

                confirmBtn.className = 'estate-dialog-btn estate-dialog-btn-primary';
                confirmBtn.textContent = confirmText;

                dialogBackdrop.classList.add('active');
                setTimeout(() => {
                    activeField.focus();
                    if (activeField.select) activeField.select();
                }, 60);

                function cleanup(result) {
                    dialogBackdrop.classList.remove('active');
                    bodyEl.style.display = 'none';
                    cancelBtn.removeEventListener('click', onCancel);
                    confirmBtn.removeEventListener('click', onConfirm);
                    dialogBackdrop.removeEventListener('click', onBackdrop);
                    document.removeEventListener('keydown', onKey);
                    resolve(result);
                }

                function onCancel() { cleanup(null); }
                function onConfirm() { cleanup(activeField.value); }
                function onBackdrop(e) { if (e.target === dialogBackdrop) cleanup(null); }
                function onKey(e) {
                    if (e.key === 'Escape') cleanup(null);
                    if (e.key === 'Enter' && inputType !== 'textarea') cleanup(activeField.value);
                }

                cancelBtn.addEventListener('click', onCancel);
                confirmBtn.addEventListener('click', onConfirm);
                dialogBackdrop.addEventListener('click', onBackdrop);
                document.addEventListener('keydown', onKey);
            });
        },

        /**
         * Show in-house Toast notification
         */
        toast: function(options) {
            initContainers();
            let msg = '';
            let type = 'info'; // 'success', 'error', 'warning', 'info'
            let title = '';
            let duration = 3500;

            if (typeof options === 'string') {
                msg = options;
            } else if (typeof options === 'object') {
                msg = options.message || '';
                type = options.type || 'info';
                title = options.title || '';
                duration = options.duration !== undefined ? options.duration : 3500;
            }

            if (!title) {
                if (type === 'success') title = 'Success';
                else if (type === 'error' || type === 'danger') title = 'Alert';
                else if (type === 'warning') title = 'Notice';
                else if (type === 'info') title = 'Information';
            }

            let iconHtml = '<i class="fa-solid fa-circle-info"></i>';
            if (type === 'success') iconHtml = '<i class="fa-solid fa-circle-check"></i>';
            else if (type === 'error' || type === 'danger') iconHtml = '<i class="fa-solid fa-circle-xmark"></i>';
            else if (type === 'warning') iconHtml = '<i class="fa-solid fa-triangle-exclamation"></i>';

            const card = document.createElement('div');
            card.className = `estate-toast-card ${type}`;
            card.innerHTML = `
                <div class="estate-toast-icon">${iconHtml}</div>
                <div class="estate-toast-content">
                    <div class="estate-toast-title">${title}</div>
                    <div class="estate-toast-text">${msg}</div>
                </div>
                <button type="button" class="estate-toast-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                <div class="estate-toast-progress">
                    <div class="estate-toast-progress-bar" style="transition-duration: ${duration}ms; transform: scaleX(1);"></div>
                </div>
            `;

            toastContainer.appendChild(card);

            // Animate In
            requestAnimationFrame(() => {
                card.classList.add('show');
                const bar = card.querySelector('.estate-toast-progress-bar');
                if (bar && duration > 0) {
                    requestAnimationFrame(() => {
                        bar.style.transform = 'scaleX(0)';
                    });
                }
            });

            function dismiss() {
                card.classList.remove('show');
                card.classList.add('hide');
                setTimeout(() => {
                    if (card.parentNode) card.parentNode.removeChild(card);
                }, 300);
            }

            const closeBtn = card.querySelector('.estate-toast-close');
            if (closeBtn) closeBtn.addEventListener('click', dismiss);

            if (duration > 0) {
                setTimeout(dismiss, duration);
            }

            return card;
        },

        /**
         * 1-Tap Copy with in-house toast feedback
         */
        copy: function(text, customSuccessMsg) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    EstateDialog.toast({
                        type: 'success',
                        title: 'Copied to Clipboard',
                        message: customSuccessMsg || `"${text}" copied successfully.`
                    });
                }).catch(() => {
                    EstateDialog.toast({
                        type: 'error',
                        title: 'Copy Failed',
                        message: 'Unable to access clipboard.'
                    });
                });
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    EstateDialog.toast({
                        type: 'success',
                        title: 'Copied to Clipboard',
                        message: customSuccessMsg || `"${text}" copied successfully.`
                    });
                } catch(e) {
                    EstateDialog.toast({ type: 'error', message: 'Unable to copy text' });
                }
                document.body.removeChild(textarea);
            }
        }
    };

    // Attach to global window
    window.EstateDialog = EstateDialog;
    window.showEstateToast = (msg, type, title) => EstateDialog.toast({ message: msg, type: type, title: title });
    window.showEstateConfirm = (msg, title, type) => EstateDialog.confirm({ message: msg, title: title, type: type });
    window.showEstateAlert = (msg, title, type) => EstateDialog.alert({ message: msg, title: title, type: type });
    window.showEstatePrompt = (msg, defaultVal, title) => EstateDialog.prompt({ message: msg, defaultValue: defaultVal, title: title });

    /**
     * GLOBAL SAFE INTERCEPTOR FOR WINDOW.ALERT
     * Overrides native window.alert to show in-house toast or modal alert!
     */
    window.alert = function(msg) {
        if (msg === undefined || msg === null) return;
        const msgStr = String(msg);
        const isError = /error|failed|invalid|cannot|warning|denied/i.test(msgStr);
        const isSuccess = /success|copied|saved|updated|dispatched|scheduled|created|deleted|added/i.test(msgStr);

        if (msgStr.length > 90 || msgStr.includes('\n')) {
            EstateDialog.alert({
                title: isError ? 'Action Error' : (isSuccess ? 'Success' : 'Notice'),
                message: msgStr,
                type: isError ? 'danger' : (isSuccess ? 'success' : 'info')
            });
        } else {
            EstateDialog.toast({
                message: msgStr,
                type: isError ? 'error' : (isSuccess ? 'success' : 'info')
            });
        }
    };

    /**
     * AUTOMATIC FORM & LINK INTERCEPTOR FOR data-confirm & onsubmit confirms
     */
    document.addEventListener('click', function(e) {
        // 1. Trigger for [data-confirm]
        const trigger = e.target.closest('[data-confirm]');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            const message = trigger.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            const title = trigger.getAttribute('data-confirm-title') || 'Confirm Action';
            const type = trigger.getAttribute('data-confirm-type') || 'danger';

            EstateDialog.confirm({
                title: title,
                message: message,
                type: type
            }).then((confirmed) => {
                if (confirmed) {
                    if (trigger.tagName === 'A' && trigger.href) {
                        window.location.href = trigger.href;
                    } else if (trigger.form) {
                        trigger.form.submit();
                    } else if (trigger.type === 'submit') {
                        const form = trigger.closest('form');
                        if (form) form.submit();
                    }
                }
            });
            return;
        }

        // 2. Intercept links with onclick="...confirm(...)"
        const confirmLink = e.target.closest('a[onclick*="confirm("], button[onclick*="confirm("]');
        if (confirmLink) {
            const onclickAttr = confirmLink.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes('confirm(')) {
                e.preventDefault();
                e.stopPropagation();
                const match = onclickAttr.match(/confirm\(\s*['"`](.*?)['"`]\s*\)/);
                const msg = match ? match[1] : 'Are you sure you want to proceed?';
                EstateDialog.confirm({
                    title: 'Please Confirm',
                    message: msg,
                    type: 'warning'
                }).then(confirmed => {
                    if (confirmed) {
                        if (confirmLink.tagName === 'A' && confirmLink.href && !confirmLink.href.startsWith('javascript:')) {
                            window.location.href = confirmLink.href;
                        }
                    }
                });
            }
        }
    }, true);

    /**
     * Intercept form onsubmit confirm dialogs
     */
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form && form.dataset && form.dataset.estateConfirmed === 'true') {
            delete form.dataset.estateConfirmed;
            return;
        }

        const onsubmitAttr = form ? form.getAttribute('onsubmit') : null;
        if (onsubmitAttr && onsubmitAttr.includes('confirm(')) {
            e.preventDefault();
            e.stopPropagation();
            const match = onsubmitAttr.match(/confirm\(\s*['"`](.*?)['"`]\s*\)/);
            const msg = match ? match[1] : 'Are you sure you want to proceed?';
            EstateDialog.confirm({
                title: 'Please Confirm',
                message: msg,
                type: 'danger'
            }).then(confirmed => {
                if (confirmed) {
                    form.dataset.estateConfirmed = 'true';
                    form.submit();
                }
            });
        }
    }, true);

})(window, document);
