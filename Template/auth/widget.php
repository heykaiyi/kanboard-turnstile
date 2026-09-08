<?php
/**
 * The placeholder the challenge is drawn into.
 *
 * Kanboard renders this after </form>, which is the closest hook it has to the
 * login form's inside; Assets/js/turnstile.js moves it above the sign-in button
 * and then renders the widget into it. Deliberately without the `cf-turnstile`
 * class, so that nothing can draw it implicitly before it has been moved.
 *
 * Nothing is emitted until a site key is set, so installing the plugin cannot
 * by itself lock anyone out — the validator makes the same call.
 */
$turnstileSiteKey = $this->app->config('turnstile_site_key');

if ($turnstileSiteKey === '') {
    return;
}
?>
<div class="turnstile-widget" data-sitekey="<?= $this->text->e($turnstileSiteKey) ?>"></div>
