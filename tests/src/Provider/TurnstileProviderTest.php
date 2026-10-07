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

use Derafu\Captcha\Provider\AbstractHttpCaptchaProvider;
use Derafu\Captcha\Provider\TurnstileProvider;
use Derafu\TestsCaptcha\Support\VerifyServer;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cloudflare Turnstile, asked for real with the secrets for tests that it
 * publishes (one always passes and one always fails), and a local server for
 * the action.
 */
#[CoversClass(TurnstileProvider::class)]
#[CoversClass(AbstractHttpCaptchaProvider::class)]
final class TurnstileProviderTest extends TestCase
{
    private const SECRET_PASS = '1x0000000000000000000000000000000AA';

    private const SECRET_FAIL = '2x0000000000000000000000000000000AA';

    private static VerifyServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = VerifyServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->forget();
    }

    private function provider(string $secret = self::SECRET_PASS, ?string $url = null): TurnstileProvider
    {
        $factory = new HttpFactory();

        return new TurnstileProvider('1x00000000000000000000AA', $secret, new Client(['timeout' => 10]), $factory, $factory, 'es', $url);
    }

    #[Test]
    public function aTokenThatTheServiceAcceptsIsValid(): void
    {
        $this->assertTrue($this->provider()->verify('any-token', 'contact'));
    }

    #[Test]
    public function aTokenThatTheServiceRejectsIsNotValid(): void
    {
        $this->assertFalse($this->provider(self::SECRET_FAIL)->verify('any-token', 'contact'));
    }

    #[Test]
    public function theServiceIsAskedWithTheSecretAndTheResponseOnly(): void
    {
        $this->provider(url: self::$server->url())->verify('turnstile-ok', 'contact');

        $this->assertSame(
            ['secret' => self::SECRET_PASS, 'response' => 'turnstile-ok'],
            self::$server->requests()[0]['fields']
        );
    }

    #[Test]
    public function theActionOfTheAnswerMustBeTheOneOfTheForm(): void
    {
        $provider = $this->provider(url: self::$server->url());

        $this->assertTrue($provider->verify('turnstile-ok', 'contact'));
        $this->assertFalse($provider->verify('turnstile-other', 'contact'));
    }

    #[Test]
    public function anAnswerWithoutActionIsValid(): void
    {
        $this->assertTrue($this->provider(url: self::$server->url())->verify('plain-ok', 'contact'));
    }

    #[Test]
    public function anAnswerThatFailsIsNotValid(): void
    {
        $this->assertFalse($this->provider(url: self::$server->url())->verify('failed', 'contact'));
    }

    #[Test]
    public function theWidgetHasTheSiteKeyTheActionAndTheLanguage(): void
    {
        $widget = $this->provider()->getWidget('Certificacion DTE');

        $this->assertStringContainsString('class="cf-turnstile mt-2 mb-4" data-sitekey="1x00000000000000000000AA"', $widget);
        $this->assertStringContainsString('data-action="certificacion-dte"', $widget);
        $this->assertStringContainsString('data-language="es"', $widget);
        $this->assertStringContainsString('<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>', $widget);
    }

    #[Test]
    public function theActionHasAtMost32Characters(): void
    {
        $widget = $this->provider()->getWidget(str_repeat('a', 50));

        $this->assertStringContainsString('data-action="' . str_repeat('a', 32) . '"', $widget);
    }

    #[Test]
    public function theFieldIsTheOneOfTheService(): void
    {
        $this->assertTrue($this->provider()->isAvailable());
        $this->assertSame('cf-turnstile-response', $this->provider()->getResponseField());
    }
}
