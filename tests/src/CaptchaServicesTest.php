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
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Captcha\Provider\ReCaptchaV3Provider;
use Derafu\Captcha\Provider\TurnstileProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\Captcha\Translation\CaptchaTranslationResourceProvider;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
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
     * @param array<string, string> $environment
     */
    private function provider(array $environment): CaptchaProviderInterface
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

        return $provider;
    }

    #[Test]
    public function withoutAProviderTheApplicationHasNoCaptcha(): void
    {
        $provider = $this->provider([]);

        $this->assertInstanceOf(UnavailableCaptchaProvider::class, $provider);
        $this->assertFalse($provider->isAvailable());
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
