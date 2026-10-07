<?php

declare(strict_types=1);

/**
 * Derafu: Captcha - Captcha providers for the forms of derafu/form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Captcha;

use Derafu\Captcha\Provider\AltchaProvider;
use Derafu\Captcha\Provider\HCaptchaProvider;
use Derafu\Captcha\Provider\ReCaptchaV3Provider;
use Derafu\Captcha\Provider\TurnstileProvider;
use Derafu\Captcha\Provider\UnavailableCaptchaProvider;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Creates the captcha provider of an application from its configuration (the
 * environment variables `CAPTCHA_PROVIDER`, `CAPTCHA_SITE_KEY` and
 * `CAPTCHA_SECRET_KEY`).
 *
 * Without a provider the application has no captcha: the forms that ask for it
 * have none. A provider that is not known, or that lacks its keys, is an error
 * of the configuration and not a captcha that fails later.
 */
final class CaptchaProviderFactory
{
    public const HCAPTCHA = 'hcaptcha';

    public const TURNSTILE = 'turnstile';

    public const RECAPTCHA_V3 = 'recaptcha-v3';

    public const ALTCHA = 'altcha';

    /**
     * Creates the provider.
     *
     * @param string|null $provider The name of the provider, empty if there is
     * none.
     * @param string|null $siteKey The public key (not used by Altcha).
     * @param string|null $secretKey The secret key (for Altcha, the key that
     * signs its challenges).
     * @param string $locale The language of the widgets.
     * @param float $minScore The lowest score that reCAPTCHA v3 accepts.
     * @throws InvalidArgumentException If the provider is not known or its keys
     * are missing.
     */
    public static function create(
        ?string $provider,
        ?string $siteKey,
        ?string $secretKey,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $locale = 'en',
        float $minScore = 0.5,
    ): CaptchaProviderInterface {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '') {
            return new UnavailableCaptchaProvider();
        }

        $siteKey = (string) $siteKey;
        $secretKey = (string) $secretKey;

        return match ($provider) {
            self::HCAPTCHA => new HCaptchaProvider(
                self::keyOf($provider, 'CAPTCHA_SITE_KEY', $siteKey),
                self::keyOf($provider, 'CAPTCHA_SECRET_KEY', $secretKey),
                $client,
                $requestFactory,
                $streamFactory,
                $locale
            ),
            self::TURNSTILE => new TurnstileProvider(
                self::keyOf($provider, 'CAPTCHA_SITE_KEY', $siteKey),
                self::keyOf($provider, 'CAPTCHA_SECRET_KEY', $secretKey),
                $client,
                $requestFactory,
                $streamFactory,
                $locale
            ),
            self::RECAPTCHA_V3 => new ReCaptchaV3Provider(
                self::keyOf($provider, 'CAPTCHA_SITE_KEY', $siteKey),
                self::keyOf($provider, 'CAPTCHA_SECRET_KEY', $secretKey),
                $client,
                $requestFactory,
                $streamFactory,
                $locale,
                minScore: $minScore
            ),
            self::ALTCHA => new AltchaProvider(
                self::keyOf($provider, 'CAPTCHA_SECRET_KEY', $secretKey),
                $locale
            ),
            default => throw new InvalidArgumentException([
                'The captcha provider "{provider}" is not known. Use one of: {providers}.',
                'provider' => $provider,
                'providers' => implode(', ', [self::HCAPTCHA, self::TURNSTILE, self::RECAPTCHA_V3, self::ALTCHA]),
            ]),
        };
    }

    /**
     * Checks that a key of the configuration is there.
     *
     * @throws InvalidArgumentException If it is not.
     */
    private static function keyOf(string $provider, string $variable, string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException([
                'The captcha provider "{provider}" needs the variable {variable}.',
                'provider' => $provider,
                'variable' => $variable,
            ]);
        }

        return $value;
    }
}
