(() => {
    'use strict';
    const submittedForms = new WeakSet();
    const pendingButtons = new Map();

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        });
    });

    document.querySelectorAll('[data-auth-code]').forEach((input) => {
        const normalize = (value) => value.replace(/[^0-9]/g, '').slice(0, 6);
        input.addEventListener('input', () => {
            input.value = normalize(input.value);
            input.removeAttribute('aria-invalid');
        });
        input.addEventListener('paste', (event) => {
            const pasted = event.clipboardData?.getData('text');
            if (pasted === undefined) return;
            event.preventDefault();
            input.value = normalize(pasted);
            input.removeAttribute('aria-invalid');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    document.querySelectorAll('[data-auth-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (submittedForms.has(form)) { event.preventDefault(); return; }
            if (!form.checkValidity()) return;
            const button = event.submitter || form.querySelector('[data-submit-button]');
            submittedForms.add(form);
            form.setAttribute('aria-busy', 'true');
            if (!button) return;
            const label = button.querySelector('[data-submit-label]');
            pendingButtons.set(button, { form, text: label?.textContent || '' });
            button.disabled = true;
            if (label) label.textContent = 'Un instant…';
            const arrow = button.querySelector('.auth-button-arrow');
            const spinner = button.querySelector('.auth-spinner');
            if (arrow) arrow.hidden = true;
            if (spinner) spinner.hidden = false;
        });
    });

    // Restoring a browser page must also restore its usable form controls.
    window.addEventListener('pageshow', () => {
        pendingButtons.forEach(({ form, text }, button) => {
            submittedForms.delete(form);
            form.removeAttribute('aria-busy');
            button.disabled = false;
            const label = button.querySelector('[data-submit-label]');
            const arrow = button.querySelector('.auth-button-arrow');
            const spinner = button.querySelector('.auth-spinner');
            if (label) label.textContent = text;
            if (arrow) arrow.hidden = false;
            if (spinner) spinner.hidden = true;
        });
        pendingButtons.clear();
        updateTimers();
    });

    const expiry = document.querySelector('[data-code-expiry]');
    const resend = document.querySelector('[data-resend-cooldown]');
    const challengeExpired = expiry?.dataset.challengeExpired === 'true';
    const loadedAt = Date.now();
    const expiryDeadline = expiry ? loadedAt + Math.max(0, Number(expiry.dataset.codeExpiry) || 0) * 1000 : null;
    const resendDeadline = resend ? loadedAt + Math.max(0, Number(resend.dataset.resendCooldown) || 0) * 1000 : null;
    let expiryAnnounced = false;
    let resendAnnounced = false;

    function updateTimers() {
        if (expiry && expiryDeadline !== null) {
            const seconds = Math.max(0, Math.ceil((expiryDeadline - Date.now()) / 1000));
            const label = expiry.querySelector('[data-expiry-label]');
            if (seconds > 0 && !challengeExpired) {
                if (label) label.textContent = `Code valable encore ${Math.floor(seconds / 60)} min ${String(seconds % 60).padStart(2, '0')} s.`;
            } else {
                expiry.classList.add('is-expired');
                if (!expiryAnnounced) {
                    expiry.setAttribute('role', 'status');
                    if (label) label.textContent = challengeExpired
                        ? 'Cette demande de connexion a expiré. Revenez à la connexion pour recommencer.'
                        : 'Ce code a expiré. Demandez un nouveau code ci-dessous.';
                    expiryAnnounced = true;
                }
                const form = document.querySelector('[data-code-form]');
                const code = form?.querySelector('[data-auth-code]');
                const submit = form?.querySelector('[data-submit-button]');
                if (code) code.disabled = true;
                if (submit) submit.disabled = true;
            }
        }
        if (resend && challengeExpired) {
            resend.disabled = true;
        } else if (resend && resendDeadline !== null && !pendingButtons.has(resend)) {
            const seconds = Math.max(0, Math.ceil((resendDeadline - Date.now()) / 1000));
            const label = resend.querySelector('[data-submit-label]');
            const status = document.querySelector('[data-resend-status]');
            resend.disabled = seconds > 0;
            if (label) label.textContent = seconds > 0 ? `Renvoyer un code (${seconds} s)` : 'Renvoyer un code';
            if (seconds === 0 && !resendAnnounced) {
                if (status && Number(resend.dataset.resendCooldown) > 0) status.textContent = 'Vous pouvez demander un nouveau code.';
                resendAnnounced = true;
            }
        }
    }
    updateTimers();
    if (expiry || resend) {
        window.setInterval(updateTimers, 1000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) updateTimers(); });
    }
})();
