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
 * The provider of an application that has not configured a captcha.
 *
 * The forms that ask for the captcha have none then, and they do not use the
 * rest of the methods: it is the same as having no provider, but it is always in
 * the container, so the services do not depend on the environment variables.
 */
final class UnavailableCaptchaProvider implements CaptchaProviderInterface
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
    public function getResponseField(): string
    {
        throw new LogicException('There is no captcha configured.');
    }

    /**
     * {@inheritDoc}
     */
    public function getWidget(string $formId): string
    {
        throw new LogicException('There is no captcha configured.');
    }

    /**
     * {@inheritDoc}
     */
    public function verify(string $token, string $formId): bool
    {
        throw new LogicException('There is no captcha configured.');
    }
}
