<?php

namespace Kanboard\Plugin\Turnstile\Validator;

use Kanboard\Validator\AuthValidator;

/**
 * The stock login chain with one more link in it.
 *
 * The Turnstile step runs before validateCredentials, so a request that fails
 * the challenge never reaches the password check and never counts towards
 * (or away from) the brute-force lockout.
 */
class TurnstileAuthValidator extends AuthValidator
{
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function validateForm(array $values)
    {
        return $this->executeValidators(
            array('validateFields', 'validateLocking', 'validateCaptcha', 'validateTurnstile', 'validateCredentials'),
            $values
        );
    }

    /**
     * Verify the widget's token with Cloudflare.
     *
     * Fails closed: once the plugin is configured, anything other than a
     * response Cloudflare positively confirms is a rejected login.
     */
    protected function validateTurnstile(array $values)
    {
        $siteKey = $this->configModel->get('turnstile_site_key');
        $secretKey = $this->configModel->get('turnstile_secret_key');

        // Not configured yet — stay out of the way rather than lock everyone
        // out of their own instance.
        if ($siteKey === '' || $secretKey === '') {
            return array(true, array());
        }

        $token = isset($values['cf-turnstile-response']) ? $values['cf-turnstile-response'] : '';

        if ($token === '') {
            return $this->reject('Turnstile: no token in the request');
        }

        try {
            $response = $this->httpClient->postForm(self::VERIFY_URL, array(
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $this->request->getIpAddress(),
            ));

            $result = json_decode($response, true);
        } catch (\Exception $e) {
            return $this->reject('Turnstile: verification request failed — '.$e->getMessage());
        }

        if (! is_array($result) || empty($result['success'])) {
            $codes = isset($result['error-codes']) ? implode(', ', (array) $result['error-codes']) : 'unknown';

            return $this->reject('Turnstile: rejected by Cloudflare ('.$codes.')');
        }

        return array(true, array());
    }

    private function reject($logMessage)
    {
        $this->logger->error($logMessage);

        return array(false, array('login' => t('Human verification failed, please try again.')));
    }
}
