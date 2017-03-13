<?php

namespace BlackwoodSeven\LogService\Provider;

use Silex\Application;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Provider\MonologServiceProvider;
use Monolog\Logger;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\WebProcessor;
use Bartlett\Monolog\Handler\CallbackFilterHandler;
use BlackwoodSeven\AmqpService\Monolog\Handler\AmqpHandler;
use Monolog\ErrorHandler;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class LogServiceProvider implements ServiceProviderInterface
{

    public function register(Container $app)
    {
        // Setup logging.
        $app->register(new MonologServiceProvider());

        $app['amqp.logger.exchange_name'] = 'log_exchange';

        $app['monolog.handlers'] = $app->extend('monolog.handlers', function ($handlers, Application $app) {
            // Log all errors from NOTICE and above to amqp.
            if ($app->offsetExists('amqp.exchanges')) {
                $handler = function () use ($app) {
                    return new AmqpHandler(
                        $app['amqp.exchanges'][$app['amqp.logger.exchange_name']],   // Exchange
                        $app['app_id'],                                              // App ID
                        $app['app_id'] . '.log.error',                               // Routing key
                        'error',                                                     // Type
                        Logger::NOTICE                                               // Minimum log level
                    );
                };

                // Don't log 4xx http errors to error queue.
                $handler = new FilterHandler($handler, Logger::NOTICE);
                $handler = new CallbackFilterHandler($handler, [
                    function ($record) {
                        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof HttpExceptionInterface) {
                            return floor($record['context']['exception']->getStatusCode() / 100) != 4;
                        }
                        return true;
                    }
                ]);
                $handlers[] = $handler;
            }

            // Log all errors from NOTICE and above to stderr. Expand newlines.
            $handler = new StreamHandler('php://stderr');
            $handler->getFormatter()->includeStacktraces();
            $handlers[] = new FilterHandler($handler, Logger::NOTICE);

            // Log all DEBUG and INFO to stdout. Expand newlines.
            $handler = new StreamHandler('php://stdout');
            $handler->getFormatter()->includeStacktraces();
            $handlers[] = new FilterHandler($handler, Logger::DEBUG, Logger::INFO);

            return $handlers;

        });

        // Log IP, URL, etc.
        $app['logger'] = $app->extend('logger', function ($logger, Application $app) {
            $logger->pushProcessor(new WebProcessor());
            return $logger;
        });

        // Setup error handlers for web and console respectively.
        \BlackwoodSeven\LogService\ErrorHandler::register($app);

        // Log all uncaught errors and exceptions.
        \Monolog\ErrorHandler::register($app['logger']);
    }
}
