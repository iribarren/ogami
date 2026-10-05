<?php

declare(strict_types=1);

// Gives phpstan-doctrine access to the entity manager and its mappings.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
assert(is_string($environment));

$kernel = new Kernel($environment, (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
