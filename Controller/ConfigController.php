<?php

namespace Kanboard\Plugin\Turnstile\Controller;

use Kanboard\Plugin\Turnstile\Validator\TurnstileAuthValidator;

class ConfigController extends \Kanboard\Controller\ConfigController
{
    public function show()
    {
        $this->response->html($this->helper->layout->config('Turnstile:config/settings', array(
            'title' => t('Settings').' &gt; '.t('Turnstile'),
        )));
    }

    /**
     * Ask Cloudflare whether the saved secret is usable.
     *
     * siteverify has no ping, so this posts a deliberately invalid token: a
     * working secret comes back with "invalid-input-response", while a bad
     * one comes back with "invalid-input-secret". Anything else means the
     * endpoint could not be reached at all.
     */
    public function test()
    {
        $this->checkCSRFParam();

        $secretKey = $this->configModel->get('turnstile_secret_key');

        if ($secretKey === '') {
            $this->flash->failure(t('No secret key is saved yet.'));
            $this->redirectToSettings();
        }

        try {
            $response = $this->httpClient->postForm(TurnstileAuthValidator::VERIFY_URL, array(
                'secret' => $secretKey,
                'response' => 'kanboard-connection-test',
            ));

            $result = json_decode($response, true);
        } catch (\Exception $e) {
            $this->flash->failure(t('Could not reach Cloudflare: %s', $e->getMessage()));
            $this->redirectToSettings();
        }

        $codes = isset($result['error-codes']) ? (array) $result['error-codes'] : array();

        if (in_array('invalid-input-secret', $codes, true) || in_array('missing-input-secret', $codes, true)) {
            $this->flash->failure(t('Cloudflare rejected the secret key.'));
        } elseif (! empty($result['success']) || in_array('invalid-input-response', $codes, true)) {
            $this->flash->success(t('The secret key works.'));
        } else {
            $this->flash->failure(t('Unexpected answer from Cloudflare: %s', implode(', ', $codes) ?: 'none'));
        }

        $this->redirectToSettings();
    }

    private function redirectToSettings()
    {
        $this->response->redirect($this->helper->url->to('ConfigController', 'show', array('plugin' => 'Turnstile')));
    }

    public function save()
    {
        $values = $this->request->getValues();

        $values = array(
            'turnstile_site_key' => isset($values['turnstile_site_key']) ? trim($values['turnstile_site_key']) : '',
            'turnstile_secret_key' => isset($values['turnstile_secret_key']) ? trim($values['turnstile_secret_key']) : '',
        );

        if ($this->configModel->save($values)) {
            $this->flash->success(t('Settings saved successfully.'));
        } else {
            $this->flash->failure(t('Unable to save your settings.'));
        }

        $this->redirectToSettings();
    }
}
