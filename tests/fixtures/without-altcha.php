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

try {
    CaptchaProviderFactory::create((string) ($_SERVER['argv'][1] ?? ''), 'site', 'secret', new Client(), $http, $http);
    echo 'created';
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
