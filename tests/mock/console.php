#!/usr/bin/env php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/FooCommand.php';

umask(0000);

$console = new \Symfony\Component\Console\Application();

$app = new \Silex\Application(array('debug' => true));

$app['console'] = $console;
$app['console.input'] = new \Symfony\Component\Console\Input\ArgvInput();
$app['console.output'] = new \Symfony\Component\Console\Output\ConsoleOutput();

$app->register(new BlackwoodSeven\LogService\Provider\LogServiceProvider());

$dir = sys_get_temp_dir();
$app['monolog.handler.stderr.wrapped'] = new Monolog\Handler\StreamHandler($dir . '/stderr.log');
$app['monolog.handler.stdout.wrapped'] = new Monolog\Handler\StreamHandler($dir . '/stdout.log');
$app['monolog.handler.amqp.wrapped'] = new Monolog\Handler\StreamHandler($dir . '/amqp.log');

$app['monolog.handler.stderr.wrapped']->setFormatter(new Monolog\Formatter\JsonFormatter());
$app['monolog.handler.stdout.wrapped']->setFormatter(new Monolog\Formatter\JsonFormatter());
$app['monolog.handler.amqp.wrapped']->setFormatter(new Monolog\Formatter\JsonFormatter());

$app->boot();

$console->addCommands(array(
    new FooCommand($app),
));

$console->run($app['console.input'], $app['console.output']);
