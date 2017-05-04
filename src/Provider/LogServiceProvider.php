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
            // Log all errors from NOTICE and above to stderr. Expand newlines.
            $handler = new StreamHandler('php://stderr');
            $handler->getFormatter()->includeStacktraces();
            return new FilterHandler($handler, Logger::NOTICE);
        };

        $app['monolog.handler.stdout'] = function(Container $app) {
            // Log all DEBUG and INFO to stdout. Expand newlines.
            $handler = new StreamHandler('php://stdout');
            $handler->getFormatter()->includeStacktraces();
            return new FilterHandler($handler, Logger::DEBUG, Logger::INFO);
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
    }
}
