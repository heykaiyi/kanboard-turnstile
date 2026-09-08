<?php
/**
 * The challenge, rendered under the login form.
 *
 * Nothing is emitted until a site key is set, so installing the plugin cannot
 * by itself lock anyone out — the validator makes the same call.
 *
 * data-theme is "auto" rather than the viewer's Kanboard theme: this only ever
 * renders on the login form, where nobody is logged in yet and there is no
 * preference to read. Auto follows the operating system instead.
 */
$turnstileSiteKey = $this->app->config('turnstile_site_key');

if ($turnstileSiteKey === '') {
    return;
}
?>
<div class="cf-turnstile turnstile-widget"
     data-sitekey="<?= $this->text->e($turnstileSiteKey) ?>"
     data-theme="auto"
     data-callback="kbTurnstileSolved"
     data-expired-callback="kbTurnstileCleared"
     data-error-callback="kbTurnstileError"></div>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
