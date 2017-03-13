<?php

namespace BlackwoodSeven\LogService;

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
        SymfonyErrorHandler::register(null, true);

        // @see https://github.com/silexphp/Silex/issues/1016
        $handler = ExceptionHandler::register($app['debug']);
        $handler->setHandler(function ($e) use ($app) {
            if ($app->offsetExists('console')) {
                $console = $app['console'];
                $output = new \Symfony\Component\Console\Output\StreamOutput(
                    fopen('php://output', 'w'),
                    \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_DEBUG,
                    true
                );

                $console->renderException($e, $output);
            }
            else {
                $event = new GetResponseForExceptionEvent(
                    $app,
                    new Request(),
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
        });
    }
}