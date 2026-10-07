<?php

declare(strict_types=1);

/**
 * Derafu: Captcha - Captcha providers for the forms of derafu/form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Captcha\Provider;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Google reCAPTCHA v3 (<https://developers.google.com/recaptcha/docs/v3>): there
 * is nothing for the visitor to solve. A script asks Google for a token when the
 * form is sent, with the id of the form as its action, and the server verifies
 * it and gets a score from 0.0 (a bot) to 1.0 (a person).
 *
 * The form is valid when the score reaches the minimum and the action is the one
 * of the form. There is no second chance for a low score: v3 has no challenge.
 *
 * The token lasts about two minutes, so it is asked when the form is sent and
 * not when the page is loaded. The widget has an inline script: a page with a
 * policy of content that forbids them needs to allow this one.
 */
final class ReCaptchaV3Provider extends AbstractHttpCaptchaProvider
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * @param float $minScore The lowest score that is accepted, from 0.0 to 1.0.
     */
    public function __construct(
        string $siteKey,
        string $secretKey,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $locale = 'en',
        ?string $verifyUrl = null,
        private readonly float $minScore = 0.5,
    ) {
        parent::__construct($siteKey, $secretKey, $client, $requestFactory, $streamFactory, $locale, $verifyUrl);
    }

    /**
     * {@inheritDoc}
     */
    public function getResponseField(): string
    {
        return 'g-recaptcha-response';
    }

    /**
     * {@inheritDoc}
     */
    public function getWidget(string $formId): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $id = 'recaptcha-' . $this->getAction($formId);
        $script = <<<'JS'
(function () {
    var input = document.getElementById(%ID%);
    var form = input && input.form;
    if (!form) { return; }
    var solved = false;
    form.addEventListener('submit', function (event) {
        if (solved) { return; }
        event.preventDefault();
        var submitter = event.submitter;
        grecaptcha.ready(function () {
            grecaptcha.execute(%KEY%, {action: %ACTION%}).then(function (token) {
                input.value = token;
                solved = true;
                if (form.requestSubmit) { form.requestSubmit(submitter); } else { form.submit(); }
            });
        });
    });
})();
JS;
        $script = strtr($script, [
            '%ID%' => json_encode($id, $flags),
            '%KEY%' => json_encode($this->siteKey, $flags),
            '%ACTION%' => json_encode($this->getAction($formId), $flags),
        ]);

        return '<input type="hidden" id="' . $this->escape($id) . '" name="' . $this->getResponseField() . '" value="">'
            . '<script src="https://www.google.com/recaptcha/api.js?render=' . rawurlencode($this->siteKey)
            . '&hl=' . rawurlencode($this->getLanguage()) . '" async defer></script>'
            . '<script>' . $script . '</script>';
    }

    /**
     * {@inheritDoc}
     */
    public function verify(string $token, string $formId): bool
    {
        $answer = $this->ask([
            'secret' => $this->secretKey,
            'response' => $token,
        ]);

        return ($answer['success'] ?? false) === true
            && is_numeric($answer['score'] ?? null)
            && (float) $answer['score'] >= $this->minScore
            && ($answer['action'] ?? null) === $this->getAction($formId)
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function getVerifyUrl(): string
    {
        return self::VERIFY_URL;
    }

    /**
     * The action of a form: its id, as a name that the service accepts (letters,
     * numbers, `/` and `_`).
     */
    private function getAction(string $formId): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_\/]/', '_', $formId);
    }
}
