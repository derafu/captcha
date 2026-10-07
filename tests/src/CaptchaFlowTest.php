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

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Derafu\Captcha\Provider\AltchaProvider;
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\DataProcessor\ProcessorFactory;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Factory\FormRendererFactory;
use Derafu\Form\Form;
use Derafu\Form\Processor\FormDataProcessor;
use Derafu\Form\Processor\FormRulesResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The providers with what they are for: the renderer and the processor of
 * derafu/form, with a form that asks for the captcha. hCaptcha is asked for real
 * with the keys for tests, and ALTCHA is solved as a browser does it.
 */
#[CoversNothing]
final class CaptchaFlowTest extends TestCase
{
    private const INVALID = 'The captcha is not valid. Try again.';

    private const UNAVAILABLE = 'The captcha could not be verified. Try again in a moment.';

    private function hcaptcha(?string $verifyUrl = null): HCaptchaProvider
    {
        $http = new HttpFactory();

        return new HCaptchaProvider(
            '10000000-ffff-ffff-ffff-000000000001',
            '0x0000000000000000000000000000000000000000',
            new Client(['timeout' => 10]),
            $http,
            $http,
            'es',
            $verifyUrl
        );
    }

    private function form(bool $captcha = true): Form
    {
        return Form::fromArray([
            'schema' => [
                'name' => 'contact',
                'type' => 'object',
                'properties' => ['email' => ['type' => 'string', 'format' => 'email', 'title' => 'Email']],
                'required' => ['email'],
            ],
            'uischema' => [
                'type' => 'VerticalLayout',
                'elements' => [['type' => 'Control', 'scope' => '#/properties/email']],
            ],
            'options' => ['captcha' => $captcha, 'csrf_protection' => false],
        ]);
    }

    private function render(?CaptchaProviderInterface $provider, bool $captcha = true): string
    {
        return FormRendererFactory::create(['captcha_provider' => $provider])->render($this->form($captcha));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function process(?CaptchaProviderInterface $provider, array $data): \Derafu\Form\Contract\Processor\ProcessResultInterface
    {
        return (new FormDataProcessor(
            new FormRulesResolver(),
            (new ProcessorFactory())->create(),
            captchaProvider: $provider
        ))->process($this->form(), $data);
    }

    #[Test]
    public function hCaptchaIsInTheFormAndTheTokenOfTheTestsPasses(): void
    {
        $html = $this->render($this->hcaptcha());

        $this->assertStringContainsString('class="h-captcha mt-2 mb-4"', $html);

        $result = $this->process($this->hcaptcha(), [
            'email' => 'ana@example.com',
            'h-captcha-response' => '10000000-aaaa-bbbb-cccc-000000000001',
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame(['email' => 'ana@example.com'], $result->getProcessedData());
    }

    #[Test]
    public function hCaptchaRejectsATokenThatItDoesNotKnow(): void
    {
        $result = $this->process($this->hcaptcha(), ['email' => 'ana@example.com', 'h-captcha-response' => 'invented']);

        $this->assertFalse($result->isValid());
        $this->assertSame([self::INVALID], $result->getFormErrors());
    }

    #[Test]
    public function aFormSentWithoutTheTokenIsRejected(): void
    {
        $result = $this->process($this->hcaptcha(), ['email' => 'ana@example.com']);

        $this->assertFalse($result->isValid());
        $this->assertSame([self::INVALID], $result->getFormErrors());
    }

    #[Test]
    public function aServiceThatDoesNotAnswerMakesTheFormNotValidAndSaysSo(): void
    {
        $result = $this->process($this->hcaptcha('http://127.0.0.1:1/verify'), [
            'email' => 'ana@example.com',
            'h-captcha-response' => '10000000-aaaa-bbbb-cccc-000000000001',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertSame([self::UNAVAILABLE], $result->getFormErrors());
    }

    #[Test]
    public function altchaIsInTheFormAndWhatTheBrowserSolvesPasses(): void
    {
        $provider = new AltchaProvider('a-secret-key', 'es', cost: 10);
        $html = $this->render($provider);

        $this->assertStringContainsString('<altcha-widget', $html);
        $this->assertSame(1, preg_match('/ challenge="([^"]*)"/', $html, $matches));
        $challenge = Challenge::fromArray(json_decode(html_entity_decode($matches[1], ENT_QUOTES), true));
        $solution = (new Altcha(hmacSignatureSecret: 'a-secret-key'))->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2(),
            challenge: $challenge,
        ));
        $this->assertNotNull($solution);

        $result = $this->process($provider, [
            'email' => 'ana@example.com',
            'altcha' => (new Payload($challenge, $solution))->toBase64(),
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame(['email' => 'ana@example.com'], $result->getProcessedData());
    }

    #[Test]
    public function withoutAConfiguredCaptchaTheFormHasNoneAndIsAccepted(): void
    {
        $provider = new UnavailableCaptchaProvider();

        $this->assertStringNotContainsString('captcha', $this->render($provider));
        $this->assertTrue($this->process($provider, ['email' => 'ana@example.com'])->isValid());
    }

    #[Test]
    public function aFormThatDoesNotAskForTheCaptchaHasNoneEvenWithAProvider(): void
    {
        $this->assertStringNotContainsString('h-captcha', $this->render($this->hcaptcha(), captcha: false));
    }
}
