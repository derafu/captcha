<?php

declare(strict_types=1);

/**
 * Derafu: Captcha - Captcha providers for the forms of derafu/form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsCaptcha;

use Derafu\Captcha\CaptchaProviderFactory;
use Derafu\Captcha\Provider\AltchaProvider;
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Captcha\Provider\ReCaptchaV3Provider;
use Derafu\Captcha\Provider\TurnstileProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The provider of the application is made from its configuration: no provider
 * is an application without captcha, and a provider that is not known or that
 * lacks its keys is an error of the configuration.
 */
#[CoversClass(CaptchaProviderFactory::class)]
#[UsesClass(HCaptchaProvider::class)]
#[UsesClass(TurnstileProvider::class)]
#[UsesClass(ReCaptchaV3Provider::class)]
#[UsesClass(AltchaProvider::class)]
#[UsesClass(UnavailableCaptchaProvider::class)]
#[UsesClass(\Derafu\Captcha\Provider\AbstractHttpCaptchaProvider::class)]
final class CaptchaProviderFactoryTest extends TestCase
{
    private function create(?string $provider, ?string $siteKey = 'site', ?string $secretKey = 'secret'): CaptchaProviderInterface
    {
        $http = new HttpFactory();

        return CaptchaProviderFactory::create($provider, $siteKey, $secretKey, new Client(), $http, $http, 'es');
    }

    /**
     * @return array<string, array{?string, class-string}>
     */
    public static function providers(): array
    {
        return [
            'hCaptcha' => ['hcaptcha', HCaptchaProvider::class],
            'Turnstile' => ['turnstile', TurnstileProvider::class],
            'reCAPTCHA v3' => ['recaptcha-v3', ReCaptchaV3Provider::class],
            'Altcha' => ['altcha', AltchaProvider::class],
            'in capitals and with spaces' => ['  HCaptcha ', HCaptchaProvider::class],
            'none' => [null, UnavailableCaptchaProvider::class],
            'empty' => ['', UnavailableCaptchaProvider::class],
            'blank' => ['   ', UnavailableCaptchaProvider::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('providers')]
    public function theNameOfTheProviderGivesItsClass(?string $name, string $class): void
    {
        $this->assertInstanceOf($class, $this->create($name));
    }

    #[Test]
    public function aProviderThatIsNotKnownIsAnErrorThatSaysWhichOnesAre(): void
    {
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The captcha provider "recaptcha" is not known. Use one of: hcaptcha, turnstile, recaptcha-v3, altcha.');

        $this->create('recaptcha');
    }

    #[Test]
    public function aProviderWithoutItsKeysIsAnErrorThatSaysWhich(): void
    {
        foreach ([
            ['hcaptcha', null, 'secret', 'CAPTCHA_SITE_KEY'],
            ['hcaptcha', 'site', '', 'CAPTCHA_SECRET_KEY'],
            ['turnstile', '', 'secret', 'CAPTCHA_SITE_KEY'],
            ['turnstile', 'site', null, 'CAPTCHA_SECRET_KEY'],
            ['recaptcha-v3', null, 'secret', 'CAPTCHA_SITE_KEY'],
            ['recaptcha-v3', 'site', null, 'CAPTCHA_SECRET_KEY'],
            ['altcha', null, null, 'CAPTCHA_SECRET_KEY'],
        ] as [$provider, $siteKey, $secretKey, $variable]) {
            try {
                $this->create($provider, $siteKey, $secretKey);
                $this->fail('"' . $provider . '" was created without ' . $variable . '.');
            } catch (TranslatableInvalidArgumentException $e) {
                $this->assertSame(
                    'The captcha provider "' . $provider . '" needs the variable ' . $variable . '.',
                    $e->getMessage()
                );
            }
        }
    }

    #[Test]
    public function altchaNeedsOnlyTheSecretKey(): void
    {
        $this->assertInstanceOf(AltchaProvider::class, $this->create('altcha', null, 'secret'));
    }

    #[Test]
    public function theMinimumScoreIsTheOneThatWasGiven(): void
    {
        $http = new HttpFactory();

        foreach ([0.9, 0.3] as $minScore) {
            $provider = CaptchaProviderFactory::create('recaptcha-v3', 'site', 'secret', new Client(), $http, $http, 'es', $minScore);

            $this->assertSame($minScore, (new \ReflectionProperty(ReCaptchaV3Provider::class, 'minScore'))->getValue($provider));
        }
    }
}
