/*!
 * Cloudflare Turnstile for Kanboard's login form.
 *
 * Kanboard's login template offers two hooks — one above the whole form and
 * one after </form> — and neither is inside it. So the plugin renders an empty
 * placeholder into the second one and this file moves it into the form, above
 * the sign-in button, before the challenge is drawn. Turnstile then creates its
 * own token field inside the form and the browser posts it like any other
 * input; nothing has to be copied anywhere.
 *
 * The widget is rendered explicitly rather than by dropping the `cf-turnstile`
 * class on the placeholder, and api.js is loaded from here rather than from the
 * template, so that ordering is decided rather than raced. Implicit rendering
 * resolves data-callback off `window` at the moment api.js runs — and Kanboard
 * loads plugin scripts with `defer` while api.js would load with `async`, so
 * api.js can win, find no callback, and drop it. The widget then says
 * "Success!" and the login is rejected for having no token. Loading api.js
 * after this file has run, and handing render() real function references,
 * makes that impossible.
 *
 * This is a file rather than an inline script because Kanboard serves
 * `default-src 'self'`, which refuses inline scripts.
 */
(function () {
    'use strict';

    var API_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    var FIELD = 'cf-turnstile-response';
    var CALLBACK = 'kbTurnstileRender';

    function widget() {
        return document.querySelector('.turnstile-widget');
    }

    /* .form-login and .form-actions are Kanboard's own markup, not a theme's,
     * so this holds on a stock install and on a restyled one alike. */
    function form() {
        return document.querySelector('.form-login form');
    }

    function showError(code) {
        var container = widget();

        if (container === null) {
            return;
        }

        var note = container.querySelector('.turnstile-error');

        if (note === null) {
            note = document.createElement('p');
            note.className = 'turnstile-error';
            container.appendChild(note);
        }

        /* Cloudflare otherwise renders a bare "Troubleshoot" link, which says
         * nothing about what went wrong. The code makes the failure
         * diagnosable from the page itself: 110200 = hostname not on the
         * widget's list, 300xxx = challenge execution failure, 400xxx =
         * invalid sitekey, 600xxx = challenge timed out. */
        note.textContent = 'Turnstile: ' + code;
    }

    function render() {
        var container = widget();

        if (container === null || typeof window.turnstile === 'undefined') {
            return;
        }

        window.turnstile.render(container, {
            sitekey: container.getAttribute('data-sitekey'),
            theme: 'auto',
            'error-callback': showError
        });
    }

    /* Belt and braces for the one thing that would fail silently: if the token
     * field is ever not inside the form when it is submitted, put it there. */
    function guard(loginForm) {
        loginForm.addEventListener('submit', function () {
            var field = loginForm.querySelector('input[name="' + FIELD + '"]');

            if (field !== null && field.value !== '') {
                return;
            }

            if (typeof window.turnstile === 'undefined') {
                return;
            }

            var token = '';

            try {
                token = window.turnstile.getResponse() || '';
            } catch (e) {
                return;
            }

            if (field === null) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = FIELD;
                loginForm.appendChild(field);
            }

            field.value = token;
        }, true);
    }

    function start() {
        var container = widget();

        if (container === null) {
            return;
        }

        var loginForm = form();

        // HIDE_LOGIN_FORM, or a template that renders no form at all: there is
        // nothing to protect, so there is nothing to draw.
        if (loginForm === null) {
            container.parentNode.removeChild(container);
            return;
        }

        /* Into the actions block rather than in front of it. A theme is free
         * to lay the login form out with flexbox and explicit `order` values —
         * Kanboard's own markup gives it no other way to get "remember me" and
         * "forgot password?" onto one line — and an `order` of its own is the
         * one thing this plugin cannot guess. Sitting inside the block that
         * holds the button means the widget is carried wherever that button
         * goes, in any layout, without knowing anything about it. */
        var actions = loginForm.querySelector('.form-actions');

        if (actions !== null) {
            actions.insertBefore(container, actions.firstChild);
        } else {
            loginForm.appendChild(container);
        }

        guard(loginForm);

        window[CALLBACK] = render;

        var script = document.createElement('script');
        script.src = API_URL + '?render=explicit&onload=' + CALLBACK;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
