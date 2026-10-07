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
use Derafu\Captcha\Provider\ReCaptchaV3Provider;
use Derafu\Form\Exception\CaptchaUnavailableException;
use Derafu\TestsCaptcha\Support\VerifyServer;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Google reCAPTCHA v3. The real service is asked with a secret that is not
 * valid (it answers that the token is not), and with the secret for tests that
 * Google publishes, which says success but gives no score nor action, so it is
 * not valid for v3. What the service gives for a real token (score and action)
 * is not something that a test can get, so it is tested with a local server
 * that answers it.
 */
#[CoversClass(ReCaptchaV3Provider::class)]
#[CoversClass(AbstractHttpCaptchaProvider::class)]
final class ReCaptchaV3ProviderTest extends TestCase
{
    private const SECRET_OF_TESTS = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

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

    private function provider(string $secret = 'a-secret', ?string $url = null, float $minScore = 0.5, string $siteKey = 'a-site-key'): ReCaptchaV3Provider
    {
        $factory = new HttpFactory();

        return new ReCaptchaV3Provider($siteKey, $secret, new Client(['timeout' => 10]), $factory, $factory, 'es_CL', $url, $minScore);
    }

    #[Test]
    public function theRealServiceSaysThatATokenThatItDoesNotKnowIsNotValid(): void
    {
        $this->assertFalse($this->provider('an-invented-secret')->verify('any-token', 'contact'));
    }

    #[Test]
    public function theSecretOfTheTestsOfGoogleIsNotAV3AnswerSoItIsNotValid(): void
    {
        $this->assertFalse($this->provider(self::SECRET_OF_TESTS)->verify('any-token', 'contact'));
    }

    #[Test]
    public function aScoreThatReachesTheMinimumAndTheActionOfTheFormAreValid(): void
    {
        $provider = $this->provider(url: self::$server->url());

        $this->assertTrue($provider->verify('ok', 'contact'));
        $this->assertSame(
            ['secret' => 'a-secret', 'response' => 'ok'],
            self::$server->requests()[0]['fields']
        );
    }

    #[Test]
    public function aScoreThatIsTheMinimumIsValidAndALowerOneIsNot(): void
    {
        $provider = $this->provider(url: self::$server->url());

        $this->assertTrue($provider->verify('edge', 'contact'));
        $this->assertFalse($provider->verify('low', 'contact'));
    }

    #[Test]
    public function theMinimumIsTheOneOfTheConfiguration(): void
    {
        $this->assertTrue($this->provider(url: self::$server->url(), minScore: 0.1)->verify('low', 'contact'));
        $this->assertFalse($this->provider(url: self::$server->url(), minScore: 0.95)->verify('ok', 'contact'));
    }

    #[Test]
    public function theActionOfTheAnswerMustBeTheOneOfTheForm(): void
    {
        $provider = $this->provider(url: self::$server->url());

        $this->assertFalse($provider->verify('other-action', 'contact'));
        $this->assertFalse($provider->verify('no-action', 'contact'));
    }

    #[Test]
    public function anAnswerWithoutScoreOrThatFailsIsNotValid(): void
    {
        $provider = $this->provider(url: self::$server->url());

        $this->assertFalse($provider->verify('no-score', 'contact'));
        $this->assertFalse($provider->verify('failed', 'contact'));
    }

    #[Test]
    public function theActionOfAFormWithCharactersThatTheServiceDoesNotAcceptIsSanitized(): void
    {
        $this->assertFalse($this->provider(url: self::$server->url())->verify('ok', 'certificacion-dte'));
        $this->assertStringContainsString('"certificacion_dte"', $this->provider()->getWidget('certificacion-dte'));
    }

    #[Test]
    public function aServiceThatDoesNotAnswerIsUnavailable(): void
    {
        $this->expectException(CaptchaUnavailableException::class);

        $this->provider(url: 'http://127.0.0.1:1/verify')->verify('ok', 'contact');
    }

    #[Test]
    public function aStatusThatIsNotOkOrAnAnswerThatIsNotAJsonIsUnavailable(): void
    {
        $provider = $this->provider(url: self::$server->url());

        foreach (['server-error', 'garbage'] as $token) {
            try {
                $provider->verify($token, 'contact');
                $this->fail('"' . $token . '" should be unavailable.');
            } catch (CaptchaUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function theWidgetAsksForTheTokenWhenTheFormIsSent(): void
    {
        $widget = $this->provider()->getWidget('contact');

        $this->assertStringContainsString('<input type="hidden" id="recaptcha-contact" name="g-recaptcha-response" value="">', $widget);
        $this->assertStringContainsString('<script src="https://www.google.com/recaptcha/api.js?render=a-site-key&hl=es" async defer></script>', $widget);
        $this->assertStringContainsString("document.getElementById(\"recaptcha-contact\")", $widget);
        $this->assertStringContainsString("grecaptcha.execute(\"a-site-key\", {action: \"contact\"})", $widget);
        $this->assertStringContainsString("form.addEventListener('submit'", $widget);
        $this->assertStringContainsString('form.requestSubmit(submitter)', $widget);
    }

    #[Test]
    public function theWidgetDoesNotLetTheKeyBreakOutOfTheScript(): void
    {
        $widget = $this->provider(siteKey: '</script><b>"\'')->getWidget('contact');

        $this->assertSame(2, substr_count($widget, '</script>'), 'Only the two scripts of the widget close.');
        $this->assertStringNotContainsString('<b>', $widget);
    }

    #[Test]
    public function theFieldIsTheOneOfTheService(): void
    {
        $this->assertTrue($this->provider()->isAvailable());
        $this->assertSame('g-recaptcha-response', $this->provider()->getResponseField());
    }
}
