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

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Derafu\Captcha\Provider\AltchaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ALTCHA, with nothing mocked: the widget gives a challenge, the challenge is
 * solved as the browser does it (with the library that solves it), and what is
 * solved is verified by the provider with its secret key.
 */
#[CoversClass(AltchaProvider::class)]
final class AltchaProviderTest extends TestCase
{
    private const SECRET = 'a-secret-key';

    /**
     * The challenge is cheap here (cost 10), so the tests are fast.
     */
    private function provider(string $secret = self::SECRET, int $expiresIn = 600): AltchaProvider
    {
        return new AltchaProvider($secret, 'es_CL', cost: 10, expiresIn: $expiresIn);
    }

    /**
     * The challenge that a widget has.
     */
    private function challengeOf(string $widget): Challenge
    {
        $this->assertSame(1, preg_match('/ challenge="([^"]*)"/', $widget, $matches));

        return Challenge::fromArray(json_decode(html_entity_decode($matches[1], ENT_QUOTES), true));
    }

    /**
     * What the visitor sends when it solves the challenge of a widget.
     */
    private function solve(string $widget, string $secret = self::SECRET): string
    {
        $challenge = $this->challengeOf($widget);
        $solution = (new Altcha(hmacSignatureSecret: $secret))->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2(),
            challenge: $challenge,
        ));
        $this->assertNotNull($solution, 'The challenge was not solved.');

        return (new Payload($challenge, $solution))->toBase64();
    }

    #[Test]
    public function aChallengeThatWasSolvedIsValidForTheFormItWasMadeFor(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->verify($this->solve($provider->getWidget('contact')), 'contact'));
    }

    #[Test]
    public function aSolutionIsNotValidForAnotherForm(): void
    {
        $provider = $this->provider();

        $this->assertFalse($provider->verify($this->solve($provider->getWidget('contact')), 'login'));
    }

    #[Test]
    public function aSolutionThatTheProviderDidNotSignIsNotValid(): void
    {
        $widget = $this->provider('another-secret')->getWidget('contact');

        $this->assertFalse($this->provider()->verify($this->solve($widget, 'another-secret'), 'contact'));
    }

    #[Test]
    public function aChallengeThatExpiredIsNotValid(): void
    {
        $provider = $this->provider(expiresIn: -10);

        $this->assertFalse($provider->verify($this->solve($provider->getWidget('contact')), 'contact'));
    }

    #[Test]
    public function whatIsNotAPayloadIsNotValid(): void
    {
        $provider = $this->provider();

        $malformed = [
            '{"a":1}',
            '{"challenge":{},"solution":{}}',
            '{"challenge":{"parameters":{}},"solution":{"counter":1}}',
            '{"challenge":[],"solution":[]}',
            '{"challenge":"x","solution":"y"}',
            '[1,2]',
            '"string"',
            'null',
            'not json',
        ];

        foreach (array_merge(['', 'not-base64!'], array_map('base64_encode', $malformed)) as $token) {
            $this->assertFalse($provider->verify($token, 'contact'), 'Token: ' . $token);
        }
    }

    #[Test]
    public function aPayloadWithAnotherDerivedKeyIsNotValid(): void
    {
        $provider = $this->provider();
        $payload = json_decode((string) base64_decode($this->solve($provider->getWidget('contact'))), true);
        $key = $payload['solution']['derivedKey'];
        $payload['solution']['derivedKey'] = ($key[0] === 'a' ? 'b' : 'a') . substr($key, 1);

        $this->assertFalse($provider->verify(base64_encode((string) json_encode($payload)), 'contact'));
    }

    #[Test]
    public function theWidgetHasTheSignedChallengeTheFieldTheLanguageAndTheScript(): void
    {
        $widget = $this->provider()->getWidget('contact');
        $challenge = $this->challengeOf($widget);

        $this->assertSame(['form' => 'contact'], $challenge->parameters->data);
        $this->assertNotNull($challenge->signature);
        $this->assertGreaterThan(time(), $challenge->parameters->expiresAt);
        $this->assertStringContainsString(' name="altcha"', $widget);
        $this->assertStringContainsString(' language="es"', $widget);
        $this->assertStringContainsString('<script async defer type="module" src="https://cdn.jsdelivr.net/npm/altcha@2/dist/altcha.min.js"></script>', $widget);
    }

    #[Test]
    public function everyWidgetHasAChallengeOfItsOwn(): void
    {
        $provider = $this->provider();

        $this->assertNotSame(
            $this->challengeOf($provider->getWidget('contact'))->signature,
            $this->challengeOf($provider->getWidget('contact'))->signature
        );
    }

    #[Test]
    public function theScriptCanBeTheOneOfTheApplication(): void
    {
        $provider = new AltchaProvider(self::SECRET, 'en', cost: 10, script: '/js/altcha.js');

        $this->assertStringContainsString('src="/js/altcha.js"', $provider->getWidget('contact'));
    }

    #[Test]
    public function theProviderIsAvailableAndTheFieldIsTheOneOfTheWidget(): void
    {
        $this->assertTrue($this->provider()->isAvailable());
        $this->assertSame('altcha', $this->provider()->getResponseField());
    }
}
