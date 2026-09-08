<div class="page-header">
    <h2><?= t('Turnstile') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('ConfigController', 'save', array('plugin' => 'Turnstile')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Site key'), 'turnstile_site_key') ?>
    <?= $this->form->text('turnstile_site_key', $values, array(), array('autocomplete="off"'), 'form-input-large') ?>

    <?= $this->form->label(t('Secret key'), 'turnstile_secret_key') ?>
    <?= $this->form->password('turnstile_secret_key', $values, array(), array('autocomplete="new-password"')) ?>

    <p class="form-help"><?= t('Leave both fields empty to turn the challenge off.') ?></p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>

<hr>

<p class="form-help"><?= t('Checks the saved secret key against Cloudflare without touching the login form.') ?></p>
<p>
    <?= $this->url->link(t('Test the connection'), 'ConfigController', 'test', array('plugin' => 'Turnstile'), true, 'btn') ?>
</p>
