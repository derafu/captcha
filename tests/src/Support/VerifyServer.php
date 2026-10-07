<?php

declare(strict_types=1);

/**
 * Derafu: Captcha - Captcha providers for the forms of derafu/form.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsCaptcha\Support;

use PHPUnit\Framework\Assert;

/**
 * A local server of PHP that speaks what the captcha services speak (see
 * `tests/fixtures/verify-router.php`), and that records what it receives.
 */
final class VerifyServer
{
    /**
     * @var resource
     */
    private $process;

    private function __construct(private readonly int $port, private readonly string $log, $process)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'captcha-verify-');

        // A free port: it is asked to the system and released right away.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        Assert::assertNotFalse($socket, (string) $error);
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../../fixtures/verify-router.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['VERIFY_LOG' => $log]
        );
        Assert::assertIsResource($process);

        // Wait until the server accepts connections.
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return new self($port, $log, $process);
            }
            usleep(100000);
        }

        Assert::fail('The local server did not start.');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/verify';
    }

    /**
     * What the server received, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        return array_map(
            fn (string $line) => json_decode($line, true),
            array_values(array_filter(explode("\n", (string) file_get_contents($this->log))))
        );
    }

    public function forget(): void
    {
        file_put_contents($this->log, '');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
    }
}
