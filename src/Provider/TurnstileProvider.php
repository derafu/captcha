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

/**
 * Cloudflare Turnstile (<https://developers.cloudflare.com/turnstile/>): a
 * widget that is solved without puzzles most of the time.
 *
 * The widget has the id of the form as its action, which is a name of up to 32
 * characters of letters, numbers, `_` and `-`; the service tells it back when it
 * verifies, and an answer for another action is not valid.
 */
final class TurnstileProvider extends AbstractHttpCaptchaProvider
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * {@inheritDoc}
     */
    public function getResponseField(): string
    {
        return 'cf-turnstile-response';
    }

    /**
     * {@inheritDoc}
     */
    public function getWidget(string $formId): string
    {
        return '<div class="cf-turnstile mt-2 mb-4" data-sitekey="' . $this->escape($this->siteKey) . '"'
            . ' data-action="' . $this->escape($this->getAction($formId)) . '"'
            . ' data-language="' . $this->escape($this->getLanguage()) . '"></div>'
            . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
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

        if (($answer['success'] ?? false) !== true) {
            return false;
        }

        // The action is not in every answer (the keys for the tests do not
        // give it): when it is, it must be the one of the form.
        return !isset($answer['action']) || $answer['action'] === $this->getAction($formId);
    }

    /**
     * {@inheritDoc}
     */
    protected function getVerifyUrl(): string
    {
        return self::VERIFY_URL;
    }

    /**
     * The action of a form: its id, as a name that the service accepts.
     */
    private function getAction(string $formId): string
    {
        return substr((string) preg_replace('/[^a-z0-9_-]/', '-', strtolower($formId)), 0, 32);
    }
}
