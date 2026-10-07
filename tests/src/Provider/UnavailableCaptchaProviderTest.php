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

use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\Translation\Exception\Core\TranslatableLogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The provider of an application without captcha says that there is none, and
 * it is an error to use it for anything else.
 */
#[CoversClass(UnavailableCaptchaProvider::class)]
final class UnavailableCaptchaProviderTest extends TestCase
{
    #[Test]
    public function itIsNotAvailable(): void
    {
        $this->assertFalse((new UnavailableCaptchaProvider())->isAvailable());
    }

    #[Test]
    public function theRestIsAnError(): void
    {
        $provider = new UnavailableCaptchaProvider();

        foreach ([
            fn () => $provider->getResponseField(),
            fn () => $provider->getWidget('contact'),
            fn () => $provider->verify('token', 'contact'),
        ] as $use) {
            try {
                $use();
                $this->fail('It was used without a captcha.');
            } catch (TranslatableLogicException $e) {
                $this->assertSame('There is no captcha configured.', $e->getMessage());
            }
        }
    }
}
