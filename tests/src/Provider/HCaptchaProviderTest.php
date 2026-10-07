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
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Form\Exception\CaptchaUnavailableException;
use Derafu\TestsCaptcha\Support\VerifyServer;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * hCaptcha, asked for real with the keys for tests that it publishes (the
 * secret and the token of the test always pass), and a local server for what
 * the real service does not give: what is sent, a status that is not 200 and an
 * answer that is not a JSON.
 */
#[CoversClass(HCaptchaProvider::class)]
#[CoversClass(AbstractHttpCaptchaProvider::class)]
final class HCaptchaProviderTest extends TestCase
{
    private const SITE_KEY = '10000000-ffff-ffff-ffff-000000000001';

    private const SECRET = '0x0000000000000000000000000000000000000000';

    private const TOKEN = '10000000-aaaa-bbbb-cccc-000000000001';

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

    private function provider(string $secret = self::SECRET, ?string $url = null, string $siteKey = self::SITE_KEY, string $locale = 'es_CL'): HCaptchaProvider
    {
        $factory = new HttpFactory();

        return new HCaptchaProvider($siteKey, $secret, new Client(['timeout' => 10]), $factory, $factory, $locale, $url);
    }

    #[Test]
    public function aTokenThatTheServiceAcceptsIsValid(): void
    {
        $this->assertTrue($this->provider()->verify(self::TOKEN, 'contact'));
    }

    #[Test]
    public function aTokenThatTheServiceDoesNotKnowIsNotValid(): void
    {
        $this->assertFalse($this->provider()->verify('an-invented-token', 'contact'));
    }

    #[Test]
    public function anEmptyTokenIsNotValid(): void
    {
        $this->assertFalse($this->provider()->verify('', 'contact'));
    }

    #[Test]
    public function aSecretThatIsNotTheOneOfTheTestsIsNotValid(): void
    {
        $this->assertFalse($this->provider('an-invented-secret')->verify(self::TOKEN, 'contact'));
    }

    #[Test]
    public function theServiceIsAskedWithTheKeysAndTheResponseInAForm(): void
    {
        $this->assertFalse($this->provider(url: self::$server->url())->verify('failed', 'contact'));

        $this->assertSame([[
            'method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'accept' => 'application/json',
            'fields' => ['secret' => self::SECRET, 'response' => 'failed', 'sitekey' => self::SITE_KEY],
        ]], self::$server->requests());
    }

    #[Test]
    public function anAnswerThatSaysSuccessIsValid(): void
    {
        $this->assertTrue($this->provider(url: self::$server->url())->verify('plain-ok', 'contact'));
    }

    #[Test]
    public function aServiceThatDoesNotAnswerIsUnavailable(): void
    {
        $this->expectException(CaptchaUnavailableException::class);
        $this->expectExceptionMessage('The captcha service did not answer.');

        $this->provider(url: 'http://127.0.0.1:1/verify')->verify(self::TOKEN, 'contact');
    }

    #[Test]
    public function aStatusThatIsNotOkIsUnavailable(): void
    {
        $this->expectException(CaptchaUnavailableException::class);
        $this->expectExceptionMessage('The captcha service answered with the status 500.');

        $this->provider(url: self::$server->url())->verify('server-error', 'contact');
    }

    #[Test]
    public function anAnswerThatIsNotAJsonIsUnavailable(): void
    {
        $this->expectException(CaptchaUnavailableException::class);
        $this->expectExceptionMessage('The answer of the captcha service is not understood.');

        $this->provider(url: self::$server->url())->verify('garbage', 'contact');
    }

    #[Test]
    public function theWidgetHasTheSiteKeyAndTheLanguage(): void
    {
        $widget = $this->provider()->getWidget('contact');

        $this->assertStringContainsString('class="h-captcha mt-2 mb-4" data-sitekey="' . self::SITE_KEY . '"', $widget);
        $this->assertStringContainsString('<script src="https://js.hcaptcha.com/1/api.js?hl=es" async defer></script>', $widget);
    }

    #[Test]
    public function theWidgetEscapesTheKeyAndTheLanguage(): void
    {
        $widget = $this->provider(siteKey: '"><script>x</script>', locale: 'e"s')->getWidget('contact');

        $this->assertStringNotContainsString('<script>x', $widget);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;x&lt;/script&gt;', $widget);
    }

    #[Test]
    public function theProviderIsAvailableAndTheFieldIsTheOneOfTheService(): void
    {
        $this->assertTrue($this->provider()->isAvailable());
        $this->assertSame('h-captcha-response', $this->provider()->getResponseField());
    }
}
