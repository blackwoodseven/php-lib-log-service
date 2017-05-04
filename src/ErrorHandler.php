<?php

namespace BlackwoodSeven\LogService;

use Monolog\Logger;
use Symfony\Component\Debug\ExceptionHandler;
use Symfony\Component\Debug\ErrorHandler as SymfonyErrorHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ErrorHandler
{
    public static function register(\Silex\Application $app)
    {
        $errorHandler = SymfonyErrorHandler::register(null, true);

        // Non-fatal errors are only logged. By default, Symfony's ErrorHandler
        // throws exceptions, but that seems a bit harsh and breaks the general
        // semantics of PHP errors.
        $logger = $app['logger'];
        $nonFatal = [
            E_NOTICE => [$logger, Logger::INFO],
            E_WARNING => [$logger, Logger::WARNING],
            E_DEPRECATED => [$logger, Logger::WARNING],
            E_USER_NOTICE => [$logger, Logger::INFO],
            E_USER_WARNING => [$logger, Logger::WARNING],
            E_USER_DEPRECATED => [$logger, Logger::WARNING],
            E_STRICT => [$logger, Logger::WARNING],
        ];
        $errorHandler->setLoggers($nonFatal);

        $throwAt = E_ALL;
        foreach (array_keys($nonFatal) as $type) {
            $throwAt &= ~$type;
        }

        // Fatal errors are thrown as exceptions.
        $errorHandler->throwAt($throwAt, true);

        // @see https://github.com/silexphp/Silex/issues/1016
        $app['logger.exception_handler'] = ExceptionHandler::register($app['debug']);
        $app['logger.exception_handler']->setHandler(function ($e) use ($app) {
            $currentRequest = $app['request_stack']->getCurrentRequest() ?? new Request();
            if ($currentRequest && $currentRequest->getRequestURI()) {
                $event = new GetResponseForExceptionEvent(
                    $app,
                    $currentRequest,
                    HttpKernelInterface::MASTER_REQUEST,
                    $e
                );

                // Hey Silex ! We have something for you, can you handle it with your exception handler ?
                $app['dispatcher']->dispatch(KernelEvents::EXCEPTION, $event);

                // And now, just display the response ;)
                $response = $event->getResponse();
                $response->sendHeaders();
                $response->sendContent();
            }
            elseif ($app->offsetExists('console') && $app['console'] instanceof \Symfony\Component\Console\Application) {
                $output = new \Symfony\Component\Console\Output\StreamOutput(
                    fopen('php://output', 'w'),
                    \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_DEBUG,
                    true
                );

                $app['console']->renderException($e, $output);
            }
            else {
                print (string) $e;
            }
        });
    }
}
