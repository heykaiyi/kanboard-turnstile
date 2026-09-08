<?php

namespace Kanboard\Plugin\Turnstile;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Plugin\Turnstile\Validator\TurnstileAuthValidator;

/**
 * Cloudflare Turnstile for the Kanboard login form.
 *
 * Kanboard ships its own image CAPTCHA, but only shows it after a few failed
 * attempts and it is trivially OCR-able. Turnstile replaces that with an
 * invisible challenge on every login.
 *
 * The check has to happen before credentials are looked at, and Kanboard runs
 * exactly one chain for that — AuthValidator::validateForm(). This plugin
 * swaps the container's `authValidator` for a subclass that inserts one more
 * step into that chain, so the widget cannot be bypassed by posting straight
 * to /login/check.
 */
class Plugin extends Base
{
    public function initialize()
    {
        // The widget itself, under the password field.
        $this->template->hook->attach('template:auth:login-form:after', 'Turnstile:auth/widget');

        // Settings live next to every other configuration screen.
        $this->template->hook->attach('template:config:sidebar', 'Turnstile:config/sidebar');

        // The token plumbing. Kanboard serves `default-src 'self'`, so this
        // cannot be an inline script.
        $this->hook->on('template:layout:js', array(
            'template' => 'plugins/Turnstile/Assets/js/turnstile.js',
        ));

        // The gate. Assigning over the key replaces the service for every
        // consumer; Pimple allows it because nothing has resolved it yet.
        $container = $this->container;
        $container['authValidator'] = function ($c) {
            return new TurnstileAuthValidator($c);
        };

        // Cloudflare's script and its iframe have to be allowed through the
        // policy — but only once the plugin is actually configured, so an
        // unused install never widens it.
        if ($this->configModel->get('turnstile_site_key', '') !== '') {
            $rules = $this->container['cspRules'];
            $rules['script-src'] = "'self' https://challenges.cloudflare.com";
            $rules['frame-src'] = "https://challenges.cloudflare.com";
            $this->setContentSecurityPolicy($rules);
        }
    }

    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
    }

    public function getPluginName()
    {
        return 'Turnstile';
    }

    public function getPluginDescription()
    {
        return t('Cloudflare Turnstile on the login form');
    }

    public function getPluginAuthor()
    {
        return 'kaiyi';
    }

    public function getPluginVersion()
    {
        return '0.4.0';
    }

    public function getPluginHomepage()
    {
        return 'https://developers.cloudflare.com/turnstile/';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.0';
    }
}
