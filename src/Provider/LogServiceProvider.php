<?php

namespace BlackwoodSeven\LogService\Provider;

use Silex\Application;
use Silex\Api\BootableProviderInterface;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Provider\MonologServiceProvider;
use Monolog\Logger;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\WebProcessor;
use Bartlett\Monolog\Handler\CallbackFilterHandler;
use Monolog\ErrorHandler;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class LogServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{

    public function register(Container $app)
    {
        // Setup logging.
        $app->register(new MonologServiceProvider());

        $app['amqp.logger.exchange_name'] = 'log_exchange';

        $app['monolog.handler.stderr'] = function(Container $app) {
            // Log all errors from NOTICE and above to stderr.
            $handler = $app['monolog.handler.stderr.wrapped'];
            return new FilterHandler($handler, Logger::NOTICE);
        };
        $app['monolog.handler.stderr.stream'] = 'php://stderr';
        $app['monolog.handler.stderr.wrapped'] = function(Container $app) {
            $handler = new StreamHandler($app['monolog.handler.stderr.stream']);
            $handler->getFormatter()->includeStacktraces();
            return $handler;
        };

        $app['monolog.handler.stdout'] = function(Container $app) {
            // Log all DEBUG and INFO to stdout.
            $handler = $app['monolog.handler.stdout.wrapped'];
            return new FilterHandler($handler, Logger::DEBUG, Logger::INFO);
        };
        $app['monolog.handler.stdout.stream'] = 'php://stdout';
        $app['monolog.handler.stdout.wrapped'] = function(Container $app) {
            $handler = new StreamHandler($app['monolog.handler.stdout.stream']);
            $handler->getFormatter()->includeStacktraces();
            return $handler;
        };

        $app['monolog.handler.amqp'] = function(Container $app) {
            $handler = $app['monolog.handler.amqp.wrapped'];
            $handler = new FilterHandler($handler, Logger::NOTICE);
            $handler = new CallbackFilterHandler($handler, $app['monolog.handler.amqp.filter_callbacks']);

            return $handler;
        };
        $app['monolog.handler.amqp.wrapped'] = function(Container $app) {
            return function() use ($app) {
                return new \BlackwoodSeven\AmqpService\Monolog\Handler\AmqpHandler(
                    $app['amqp.exchanges'][$app['amqp.logger.exchange_name']],   // Exchange
                    $app['app_id'],                                              // App ID
                    $app['app_id'] . '.log.error',                               // Routing key
                    'error',                                                     // Type
                    Logger::NOTICE                                               // Minimum log level
                );
            };
        };

        $app['monolog.handler.amqp.filter_callbacks'] = [
            function ($record) {
                // 4xx HTTP errors are not important enought to be sent to the error queue.
                if (isset($record['context']['exception']) && $record['context']['exception'] instanceof HttpExceptionInterface) {
                    return floor($record['context']['exception']->getStatusCode() / 100) != 4;
                }

                // Despite running with ERRMODE == PDO::ERRMODE_EXCEPTION, PDO triggers a PHP warning
                // in certain situations when the MySQL server does not respond as expected in
                // addition to throwing an exception. See https://bugs.php.net/bug.php?id=63812
                // We often catch such exceptions and silently reconnect to the database, so
                // the adjoining notice can also be ignored.
                if (strpos($record['message'], 'PDO::query(): MySQL server has gone away') !== false ||
                    strpos($record['message'], "PDO::query(): Error reading result set's header") !== false ||
                    strpos($record['message'], 'Error while sending QUERY packet') !== false) {

                    return false;
                }

                return true;
            }
        ];

        $app['monolog.handlers'] = $app->extend('monolog.handlers', function ($handlers, Application $app) {
            if (isset($app['monolog.handler.stderr'])) {
                $handlers[] = $app['monolog.handler.stderr'];
            }

            if (isset($app['monolog.handler.stdout'])) {
                $handlers[] = $app['monolog.handler.stdout'];
            }

            // Log all errors from NOTICE and above to amqp.
            if (isset($app['monolog.handler.amqp']) && isset($app['amqp.exchanges'])) {
                $handlers[] = $app['monolog.handler.amqp'];
            }

            return $handlers;

        });

        // Log IP, URL, etc.
        $app['logger'] = $app->extend('logger', function ($logger, Application $app) {
            $logger->pushProcessor(new WebProcessor());
            return $logger;
        });

        // The native Monolog error handler does not work well with Symfony\Component\Debug\ErrorHandler
        // when not all errors are thrown (see Symfony\Component\Debug\ErrorHandler::throwErrors).
        // Instead we make it invoke Monolog using Symfony\Component\Debug\ErrorHandler::setLoggers().
        $app['monolog.use_error_handler'] = false;
    }

    public function boot(Application $app)
    {
        // Setup error handlers for web and console respectively.
        \BlackwoodSeven\LogService\ErrorHandler::register($app);

        // Disable the default behavior, where Console component catches exceptions, prints them and
        // exists without futher logging.
        // The Symfony HTTP Kernel also catches exceptions and prints them, but it also logs them,
        // so that will not cause us problems.
        if (isset($app['console'])) {
            $app['console']->setCatchExceptions(false);
        } elseif (PHP_SAPI === 'cli' &&
                  isset($GLOBALS['console']) &&
                  $GLOBALS['console'] instanceof \Symfony\Component\Console\Application) {

            $app['logger']->info(__CLASS__ . ': You seem to be running a console application, but $app["console"] is not defined');
        }
    }
}
