<li <?= $this->app->checkMenuSelection('ConfigController', 'show', 'Turnstile') ?>>
    <?= $this->url->link(t('Turnstile'), 'ConfigController', 'show', array('plugin' => 'Turnstile')) ?>
</li>
