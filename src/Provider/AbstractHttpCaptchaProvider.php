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

use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Exception\CaptchaUnavailableException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A captcha service that is asked over HTTP what the visitor solved: it gets the
 * secret key and the response of the visitor, in a form of the kind
 * `application/x-www-form-urlencoded`, and answers with a JSON.
 *
 * The client is a PSR-18 one, whose timeout is the one of the request. When the
 * service can not be asked (the client fails, the status is not 200, the answer
 * is not a JSON) the provider says so with `CaptchaUnavailableException`, so the
 * form is not valid and the visitor can try again, instead of letting anybody
 * pass because the service did not answer.
 */
abstract class AbstractHttpCaptchaProvider implements CaptchaProviderInterface
{
    /**
     * @param string $siteKey The public key, that goes in the widget.
     * @param string $secretKey The secret key, that goes in the verification.
     * @param ClientInterface $client Asks the service.
     * @param string $locale The language of the widget (`es`, `es_CL`...).
     * @param string|null $verifyUrl The URL of the verification, when it is not
     * the one of the service (for example a server for the tests).
     */
    public function __construct(
        protected readonly string $siteKey,
        protected readonly string $secretKey,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        protected readonly string $locale = 'en',
        private readonly ?string $verifyUrl = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * The URL of the verification of the service.
     */
    abstract protected function getVerifyUrl(): string;

    /**
     * Asks the service.
     *
     * @param array<string, string> $fields The fields of the verification.
     * @return array<string, mixed> The answer of the service.
     * @throws CaptchaUnavailableException If it could not be asked.
     */
    protected function ask(array $fields): array
    {
        $request = $this->requestFactory
            ->createRequest('POST', $this->verifyUrl ?? $this->getVerifyUrl())
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream(http_build_query($fields)))
        ;

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new CaptchaUnavailableException('The captcha service did not answer.', 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new CaptchaUnavailableException([
                'The captcha service answered with the status {status}.',
                'status' => $response->getStatusCode(),
            ]);
        }

        $answer = json_decode((string) $response->getBody(), true);

        if (!is_array($answer)) {
            throw new CaptchaUnavailableException('The answer of the captcha service is not understood.');
        }

        return $answer;
    }

    /**
     * The code of the language of the widget (`es` for `es_CL`).
     */
    protected function getLanguage(): string
    {
        return strtolower((string) preg_split('/[-_]/', $this->locale)[0]);
    }

    /**
     * Escapes a text for an attribute of the HTML.
     */
    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
