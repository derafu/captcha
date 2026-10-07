<?php

declare(strict_types=1);

/**
 * Derafu: Captcha - Captcha providers for the forms of derafu/form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Captcha\Provider;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use InvalidArgumentException;

/**
 * ALTCHA (<https://altcha.org>): a proof of work that the browser of the visitor
 * solves, with no third party: the server makes the challenge and verifies the
 * solution by itself, with a secret key, and it needs no connection to any
 * service.
 *
 * The challenge goes in the widget, signed, and it has the id of the form and an
 * expiry, so what was solved for a form is not good for another, and it does
 * not last. A solution is not used up: it can be sent again until the challenge
 * expires (the CSRF token of the form is the protection against a form that is
 * sent by someone else).
 *
 * The script of the widget is loaded from a CDN by default; an application that
 * does not want it serves the file itself and gives its URL.
 */
final class AltchaProvider implements CaptchaProviderInterface
{
    private const SCRIPT = 'https://cdn.jsdelivr.net/npm/altcha@2/dist/altcha.min.js';

    private readonly Altcha $altcha;

    private readonly Pbkdf2 $algorithm;

    /**
     * @param string $secretKey The secret key that signs the challenges.
     * @param string $locale The language of the widget.
     * @param int $cost The cost of the key derivation: what the browser of the
     * visitor has to work to solve it.
     * @param int $expiresIn Seconds that a challenge lasts.
     * @param string $script The URL of the script of the widget.
     */
    public function __construct(
        string $secretKey,
        private readonly string $locale = 'en',
        private readonly int $cost = 5000,
        private readonly int $expiresIn = 600,
        private readonly string $script = self::SCRIPT,
    ) {
        $this->algorithm = new Pbkdf2();
        $this->altcha = new Altcha(
            hmacSignatureSecret: $secretKey,
            hmacKeySignatureSecret: hash_hmac('sha256', 'altcha-key', $secretKey),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getResponseField(): string
    {
        return 'altcha';
    }

    /**
     * {@inheritDoc}
     */
    public function getWidget(string $formId): string
    {
        $challenge = $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm,
            cost: $this->cost,
            counter: random_int($this->cost, $this->cost * 2),
            data: ['form' => $formId],
            expiresAt: time() + $this->expiresIn,
        ));

        return '<altcha-widget class="d-block mt-2 mb-4"'
            . ' challenge="' . $this->escape($challenge->toJson()) . '"'
            . ' name="' . $this->getResponseField() . '"'
            . ' language="' . $this->escape(strtolower((string) preg_split('/[-_]/', $this->locale)[0])) . '"></altcha-widget>'
            . '<script async defer type="module" src="' . $this->escape($this->script) . '"></script>';
    }

    /**
     * {@inheritDoc}
     */
    public function verify(string $token, string $formId): bool
    {
        try {
            $payload = Payload::fromBase64($token);

            $result = $this->altcha->verifySolution(new VerifySolutionOptions(
                algorithm: $this->algorithm,
                payload: $payload,
            ));
        } catch (InvalidArgumentException) {
            return false;
        }

        // The data of the challenge is signed with it: it is the form that the
        // challenge was made for.
        return $result->verified
            && ($payload->challenge->parameters->data['form'] ?? null) === $formId
        ;
    }

    /**
     * Escapes a text for an attribute of the HTML.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
