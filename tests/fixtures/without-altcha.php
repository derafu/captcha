<?php

declare(strict_types=1);

// Creates the provider `altcha` in a PHP where the package of ALTCHA is not
// installed (its namespace is taken out of the autoloader), and prints what
// happens: the class of the exception and its message.

use Derafu\Captcha\CaptchaProviderFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
$loader->setPsr4('AltchaOrg\\Altcha\\', []);

$http = new HttpFactory();
$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    throw new InvalidArgumentException('The command arguments must be an array.');
}
$provider = $arguments[1] ?? '';
if (!is_string($provider)) {
    throw new InvalidArgumentException('The provider argument must be a string.');
}

try {
    CaptchaProviderFactory::create($provider, 'site', 'secret', new Client(), $http, $http);
    echo 'created';
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
