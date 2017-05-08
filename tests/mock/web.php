<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Silex\Application;

$app = new Application(array('debug' => true));

$app->register(new BlackwoodSeven\LogService\Provider\LogServiceProvider());

$app->get('/noop', function(Application $app) {
    return 'request completed';
});
$app->get('/log-info-in-controller', function(Application $app) {
    $app['logger']->info('this is info');
    $app['logger']->info('this is more info');
    return 'request completed';
});
$app->get('/log-warn-in-controller', function(Application $app) {
    $app['logger']->warn('this is a warning');
    return 'request completed';
});
$app->get('/notice-in-controller', function(Application $app) {
    $foo = $undefined;
    return 'request completed';
});
$app->get('/warning-in-controller', function(Application $app) {
    fopen();
    return 'request completed';
});
$app->get('/fatal-in-controller', function(Application $app) {
    does_not_exist();
    return 'request completed';
});
$app->get('/recoverable-in-controller', function(Application $app) {
    new Application('invalid');
    return 'request completed';
});
$app->get('/exception-in-controller', function(Application $app) {
    throw new \Exception('throwing in controller');
    return 'request completed';
});

$dir = sys_get_temp_dir();
$app['monolog.handler.stderr.stream'] = $dir . '/stderr.log';
$app['monolog.handler.stdout.stream'] = $dir . '/stdout.log';
$app['monolog.handler.amqp.wrapped'] = new Monolog\Handler\StreamHandler($dir . '/amqp.log');

$app['monolog.handler.stderr.wrapped']->setFormatter(new Monolog\Formatter\JsonFormatter());
$app['monolog.handler.stdout.wrapped']->setFormatter(new Monolog\Formatter\JsonFormatter());
$app['monolog.handler.amqp.wrapped']->setFormatter(new Monolog\Formatter\JsonFormatter());

$app->run();
