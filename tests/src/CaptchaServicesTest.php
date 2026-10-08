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

use Derafu\Captcha\Provider\AltchaProvider;
use Derafu\Captcha\Provider\DisabledCaptchaProvider;
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Captcha\Provider\ReCaptchaV3Provider;
use Derafu\Captcha\Provider\TurnstileProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\Captcha\Translation\CaptchaTranslationResourceProvider;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\VarExporter\LazyObjectInterface;

/**
 * An application that imports the services of the package gets, for the interface
 * that derafu/form asks for, the provider of its configuration (the environment
 * variables `CAPTCHA_*`), or one that is not available if it has none.
 */
#[CoversNothing]
final class CaptchaServicesTest extends TestCase
{
    private const VARIABLES = ['CAPTCHA_PROVIDER', 'CAPTCHA_SITE_KEY', 'CAPTCHA_SECRET_KEY', 'CAPTCHA_MIN_SCORE'];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    /**
     * The provider that the container gives: the proxy, that makes the real one
     * the first time that it is used.
     *
     * @param array<string, string> $environment
     */
    private function lazyProvider(array $environment): CaptchaProviderInterface
    {
        foreach ($environment as $name => $value) {
            putenv($name . '=' . $value);
        }

        $container = new ContainerBuilder();
        $container->setParameter('app.locale', 'es');
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/resources/config')))
            ->load('captcha-services.yaml');

        $container->register(HttpFactory::class, HttpFactory::class);
        $container->register(ClientInterface::class, Client::class);
        $container->setAlias(RequestFactoryInterface::class, HttpFactory::class);
        $container->setAlias(StreamFactoryInterface::class, HttpFactory::class);
        $container->getDefinition(CaptchaProviderInterface::class)->setPublic(true);
        $container->compile(true);

        $provider = $container->get(CaptchaProviderInterface::class);
        $this->assertInstanceOf(CaptchaProviderInterface::class, $provider);
        $this->assertInstanceOf(LazyObjectInterface::class, $provider, 'The provider must be lazy.');

        return $provider;
    }

    /**
     * The real provider of the configuration.
     *
     * @param array<string, string> $environment
     */
    private function provider(array $environment): CaptchaProviderInterface
    {
        $provider = $this->lazyProvider($environment);
        $this->assertInstanceOf(LazyObjectInterface::class, $provider);
        $real = $provider->initializeLazyObject();
        $this->assertInstanceOf(CaptchaProviderInterface::class, $real);

        return $real;
    }

    #[Test]
    public function aProviderThatIsNotWellConfiguredFailsWhereItIsUsedAndNotWhenItIsGiven(): void
    {
        // The renderer of the forms is given the provider in the first page that is
        // rendered: the application must not fail there.
        $provider = $this->lazyProvider(['CAPTCHA_PROVIDER' => 'altcha']);
        $this->assertInstanceOf(LazyObjectInterface::class, $provider);
        $this->assertFalse($provider->isLazyObjectInitialized());

        // And it says what to fix when a form uses it.
        try {
            $provider->isAvailable();
            $this->fail('The provider that lacks its key worked.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The captcha provider "altcha" needs the variable CAPTCHA_SECRET_KEY.', $e->getMessage());
        }
    }

    #[Test]
    public function aProviderThatIsNotKnownFailsWhereItIsUsed(): void
    {
        $provider = $this->lazyProvider(['CAPTCHA_PROVIDER' => 'recaptcha']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The captcha provider "recaptcha" is not known.');

        $provider->getWidget('contact');
    }

    #[Test]
    public function withoutAProviderTheApplicationDidNotConfigureACaptcha(): void
    {
        $provider = $this->provider([]);

        $this->assertInstanceOf(UnavailableCaptchaProvider::class, $provider);
        $this->assertFalse($provider->isAvailable());
        $this->assertFalse($provider->isDisabled());
    }

    #[Test]
    public function noneIsAnApplicationThatDecidedNotToHaveACaptcha(): void
    {
        $provider = $this->provider(['CAPTCHA_PROVIDER' => 'none']);

        $this->assertInstanceOf(DisabledCaptchaProvider::class, $provider);
        $this->assertFalse($provider->isAvailable());
        $this->assertTrue($provider->isDisabled());
    }

    #[Test]
    public function theProviderOfTheConfigurationIsTheOneOfTheForms(): void
    {
        $this->assertInstanceOf(HCaptchaProvider::class, $this->provider([
            'CAPTCHA_PROVIDER' => 'hcaptcha', 'CAPTCHA_SITE_KEY' => 'site', 'CAPTCHA_SECRET_KEY' => 'secret',
        ]));
        $this->assertInstanceOf(TurnstileProvider::class, $this->provider([
            'CAPTCHA_PROVIDER' => 'turnstile', 'CAPTCHA_SITE_KEY' => 'site', 'CAPTCHA_SECRET_KEY' => 'secret',
        ]));
        $this->assertInstanceOf(ReCaptchaV3Provider::class, $this->provider([
            'CAPTCHA_PROVIDER' => 'recaptcha-v3', 'CAPTCHA_SITE_KEY' => 'site', 'CAPTCHA_SECRET_KEY' => 'secret', 'CAPTCHA_MIN_SCORE' => '0.7',
        ]));
        $this->assertInstanceOf(AltchaProvider::class, $this->provider([
            'CAPTCHA_PROVIDER' => 'altcha', 'CAPTCHA_SECRET_KEY' => 'secret',
        ]));
    }

    #[Test]
    public function theWidgetIsInTheLanguageOfTheApplication(): void
    {
        $provider = $this->provider([
            'CAPTCHA_PROVIDER' => 'hcaptcha', 'CAPTCHA_SITE_KEY' => 'site', 'CAPTCHA_SECRET_KEY' => 'secret',
        ]);

        $this->assertStringContainsString('api.js?hl=es', $provider->getWidget('contact'));
    }

    #[Test]
    public function theTranslationsOfThePackageAreGivenToTheTranslator(): void
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/resources/config')))
            ->load('captcha-services.yaml');

        $this->assertArrayHasKey(
            'derafu_translation.resource_provider',
            $container->getDefinition(CaptchaTranslationResourceProvider::class)->getTags()
        );
    }
}
