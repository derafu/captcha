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
 * hCaptcha (<https://www.hcaptcha.com/>): a widget that the visitor solves.
 */
final class HCaptchaProvider extends AbstractHttpCaptchaProvider
{
    private const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    /**
     * {@inheritDoc}
     */
    public function getResponseField(): string
    {
        return 'h-captcha-response';
    }

    /**
     * {@inheritDoc}
     */
    public function getWidget(string $formId): string
    {
        return '<div class="h-captcha mt-2 mb-4" data-sitekey="' . $this->escape($this->siteKey) . '"></div>'
            . '<script src="https://js.hcaptcha.com/1/api.js?hl=' . $this->escape($this->getLanguage()) . '" async defer></script>';
    }

    /**
     * {@inheritDoc}
     */
    public function verify(string $token, string $formId): bool
    {
        $answer = $this->ask([
            'secret' => $this->secretKey,
            'response' => $token,
            'sitekey' => $this->siteKey,
        ]);

        return ($answer['success'] ?? false) === true;
    }

    /**
     * {@inheritDoc}
     */
    protected function getVerifyUrl(): string
    {
        return self::VERIFY_URL;
    }
}
