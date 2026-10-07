<?php

declare(strict_types=1);

/**
 * Derafu: Captcha - Captcha providers for the forms of derafu/form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsCaptcha\Provider;

use Derafu\Captcha\Provider\DisabledCaptchaProvider;
use Derafu\Translation\Exception\Core\TranslatableLogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The provider of an application that decided not to have a captcha says that it
 * is disabled on purpose, which is not the same as not available, and it is an
 * error to use it for anything else.
 */
#[CoversClass(DisabledCaptchaProvider::class)]
final class DisabledCaptchaProviderTest extends TestCase
{
    #[Test]
    public function itIsDisabledOnPurposeAndNotAvailable(): void
    {
        $this->assertTrue((new DisabledCaptchaProvider())->isDisabled());
        $this->assertFalse((new DisabledCaptchaProvider())->isAvailable());
    }

    #[Test]
    public function theRestIsAnError(): void
    {
        $provider = new DisabledCaptchaProvider();

        foreach ([
            fn () => $provider->getResponseField(),
            fn () => $provider->getWidget('contact'),
            fn () => $provider->verify('token', 'contact'),
        ] as $use) {
            try {
                $use();
                $this->fail('It was used with the captcha disabled.');
            } catch (TranslatableLogicException $e) {
                $this->assertSame('The captcha is disabled.', $e->getMessage());
            }
        }
    }
}
