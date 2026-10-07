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

use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Translation\Exception\Core\TranslatableLogicException as LogicException;

/**
 * The provider of an application that decided, on purpose, not to have a captcha
 * (`CAPTCHA_PROVIDER=none`).
 *
 * The forms that are protected with the captcha have none then, and it is not an
 * error. It is not the same as an application that did not configure anything
 * (see `UnavailableCaptchaProvider`): there the forms fail and say so, so a form
 * is never left open because nobody thought of the captcha.
 */
final class DisabledCaptchaProvider implements CaptchaProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isDisabled(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getResponseField(): string
    {
        throw new LogicException('The captcha is disabled.');
    }

    /**
     * {@inheritDoc}
     */
    public function getWidget(string $formId): string
    {
        throw new LogicException('The captcha is disabled.');
    }

    /**
     * {@inheritDoc}
     */
    public function verify(string $token, string $formId): bool
    {
        throw new LogicException('The captcha is disabled.');
    }
}
