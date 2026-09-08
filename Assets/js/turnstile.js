/*!
 * Cloudflare Turnstile for Kanboard's login form.
 *
 * The hook that renders the widget fires after </form>, so the token input
 * Turnstile creates for itself sits outside the form and is never posted.
 * Rather than move the widget — which races against api.js rendering it —
 * this puts its own hidden input inside the form and fills it from the
 * widget, so the value is posted with the credentials.
 *
 * Loaded as a file rather than inline because Kanboard serves
 * `default-src 'self'` and inline scripts are refused.
 */
(function () {
    'use strict';

    var FIELD = 'cf-turnstile-response';

    function getForm() {
        return document.querySelector('.form-login form');
    }

    function getInput() {
        var form = getForm();

        if (form === null) {
            return null;
        }

        var input = form.querySelector('input[name="' + FIELD + '"]');

        if (input === null) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = FIELD;
            form.appendChild(input);
        }

        return input;
    }

    function setToken(token) {
        var input = getInput();

        if (input !== null) {
            input.value = token || '';
        }
    }

    /* Where Turnstile itself keeps the solved token: a hidden input inside the
     * widget container, which the API object also reads back through
     * getResponse(). Either one is the token the callback would have handed
     * over, so this is what makes the callback optional. */
    function readWidgetToken() {
        var el = document.querySelector('.turnstile-widget input[name="' + FIELD + '"]');

        if (el !== null && el.value !== '') {
            return el.value;
        }

        if (window.turnstile && typeof window.turnstile.getResponse === 'function') {
            try {
                return window.turnstile.getResponse() || '';
            } catch (e) {
                return '';
            }
        }

        return '';
    }

    /* Named on the widget through data-callback / data-expired-callback /
     * data-error-callback, and resolved off window when Turnstile fires. */
    window.kbTurnstileSolved = function (token) {
        setToken(token);
    };

    window.kbTurnstileCleared = function () {
        setToken('');
    };

    /* Cloudflare hands the error callback a code and otherwise renders a bare
     * "Troubleshoot" link, which says nothing about what went wrong. Showing
     * the code makes the failure diagnosable from the page itself:
     * 110200 = domain not allowed, 300xxx = challenge execution failure,
     * 400xxx = invalid sitekey, 600xxx = challenge timed out. */
    window.kbTurnstileError = function (code) {
        setToken('');

        var widget = document.querySelector('.turnstile-widget');

        if (widget === null) {
            return;
        }

        var note = widget.querySelector('.turnstile-error');

        if (note === null) {
            note = document.createElement('p');
            note.className = 'turnstile-error';
            widget.appendChild(note);
        }

        note.textContent = 'Turnstile: ' + code;
    };

    function bind() {
        var form = getForm();

        if (form === null) {
            return;
        }

        getInput();

        /* The callbacks above are the fast path, not the guarantee. Kanboard
         * loads this file with `defer` while the widget loads api.js with
         * `async`, so api.js can render the widget before this file has run —
         * and Turnstile resolves data-callback off window at render time, which
         * silently drops it. The symptom is a widget that says it succeeded and
         * a login that is rejected for having no token. Reading the token back
         * when the form is submitted makes the load order irrelevant. */
        form.addEventListener('submit', function () {
            var input = getInput();

            if (input !== null && input.value === '') {
                input.value = readWidgetToken();
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
