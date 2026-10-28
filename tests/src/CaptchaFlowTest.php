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
use Derafu\Captcha\Provider\DisabledCaptchaProvider;
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Contract\Processor\FormDataProcessorInterface;
use Derafu\Form\Contract\Processor\ProcessResultInterface;
use Derafu\Form\Contract\Renderer\FormRendererInterface;
use Derafu\Form\Form;
use Derafu\Translation\Exception\Core\TranslatableLogicException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Twig\Error\RuntimeError as TwigRuntimeError;

/**
 * The providers with what they are for: the renderer and the processor of
 * derafu/form, with a form that is protected with the captcha. hCaptcha is asked for real
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
            'options' => ['captcha_protection' => $captcha, 'csrf_protection' => false],
        ]);
    }

    private function render(?CaptchaProviderInterface $provider, bool $captcha = true): string
    {
        $renderer = $this->services($provider)->get(FormRendererInterface::class);
        self::assertInstanceOf(FormRendererInterface::class, $renderer);

        return $renderer->render($this->form($captcha));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function process(?CaptchaProviderInterface $provider, array $data): ProcessResultInterface
    {
        $processor = $this->services($provider)->get(FormDataProcessorInterface::class);
        self::assertInstanceOf(FormDataProcessorInterface::class, $processor);

        return $processor->process($this->form(), $data);
    }

    private function services(?CaptchaProviderInterface $provider): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 2));
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__) . '/fixtures/config')))
            ->load('form-flow.yaml');
        if ($provider === null) {
            $container->removeDefinition(CaptchaProviderInterface::class);
        }
        foreach ([FormRendererInterface::class, FormDataProcessorInterface::class] as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
        $container->compile(true);
        if ($provider !== null) {
            $container->set(CaptchaProviderInterface::class, $provider);
        }

        return $container;
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
        $encoded = $matches[1] ?? null;
        $this->assertIsString($encoded);
        $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES), true);
        $this->assertIsArray($decoded);
        $data = [];
        foreach ($decoded as $key => $value) {
            $this->assertIsString($key);
            $data[$key] = $value;
        }
        $challenge = Challenge::fromArray($data);
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
    public function withoutAConfiguredCaptchaAProtectedFormFailsAndSaysWhatToConfigure(): void
    {
        $provider = new UnavailableCaptchaProvider();

        foreach ([
            fn () => $this->render($provider),
            fn () => $this->process($provider, ['email' => 'ana@example.com']),
        ] as $use) {
            try {
                $use();
                $this->fail('A protected form was used without a captcha.');
            } catch (\Throwable $e) {
                $logic = $e instanceof TwigRuntimeError ? $e->getPrevious() : $e;
                $this->assertInstanceOf(TranslatableLogicException::class, $logic);
                $this->assertStringContainsString('CAPTCHA_PROVIDER=altcha', $logic->getMessage());
            }
        }
    }

    #[Test]
    public function anApplicationThatDisabledTheCaptchaOnPurposeHasNoneAndNoError(): void
    {
        $provider = new DisabledCaptchaProvider();

        $this->assertStringNotContainsString('captcha', $this->render($provider));
        $this->assertTrue($this->process($provider, ['email' => 'ana@example.com'])->isValid());
    }

    #[Test]
    public function aFormThatIsNotProtectedHasNoCaptchaEvenWithAProvider(): void
    {
        $this->assertStringNotContainsString('h-captcha', $this->render($this->hcaptcha(), captcha: false));
    }
}
