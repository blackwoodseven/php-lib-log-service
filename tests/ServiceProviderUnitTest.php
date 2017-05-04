<?php
namespace BlackwoodSeven\Tests\LogService;

use BlackwoodSeven\LogService\Provider\LogServiceProvider;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ServiceProviderUnitTest extends \PHPUnit_Framework_TestCase
{
    public function testRegistration()
    {
        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());

        $this->assertTrue($app->offsetExists('monolog.handlers'), 'Logger service was not registered');
        $this->assertCount(2, $app['monolog.handlers']);
        $this->assertContains($app['monolog.handler.stdout'], $app['monolog.handlers']);
        $this->assertContains($app['monolog.handler.stderr'], $app['monolog.handlers']);
    }

    public function testRegistrationAmqpHandler()
    {
        $app = new \Silex\Application();

        // We test that it works even when the AMQP service provicer is registered
        // *after* the log provider.
        $app->register(new LogServiceProvider());
        $app->register(new \BlackwoodSeven\AmqpService\ServiceProvider());

        $this->assertTrue($app->offsetExists('monolog.handlers'), 'Logger service was not registered');
        $this->assertCount(3, $app['monolog.handlers']);
        $this->assertContains($app['monolog.handler.amqp'], $app['monolog.handlers']);
    }

    public function testRegistrationUnsettingHandlers()
    {
        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());

        unset($app['monolog.handler.stdout']);

        $this->assertCount(1, $app['monolog.handlers']);
        $this->assertContains($app['monolog.handler.stderr'], $app['monolog.handlers']);
    }

    public function testWebProcessor()
    {
        $_SERVER['REQUEST_URI'] = '/foobar.php';

        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());

        $handler = new TestHandler();
        $app['monolog.handlers'] = [$handler];

        $app['logger']->info('Lorem ipsum');

        $this->assertCount(1, $handler->getRecords());
        list($record) = $handler->getRecords();
        $this->assertEquals('/foobar.php', $record['extra']['url']);

        unset($_SERVER['REQUEST_URI']);
    }

    public function testErrorHandlerFatal()
    {
        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());
        $app['monolog.handlers'] = [];
        $app->boot();

        $thrown = false;
        try {
            trigger_error('this is a user error', E_USER_ERROR);
        } catch (\Throwable $e) {
            $thrown = true;
            $this->assertContains('this is a user error', $e->getMessage());
        }
        $this->assertTrue($thrown);
    }

    public function testExceptionWeb()
    {
        $initialObLevel = ob_get_level();

        $app = new \Silex\Application(['debug' => true]);
        $app->register(new LogServiceProvider());
        $app['monolog.handlers'] = [];
        $app->boot();

        $e = new \Exception('Lorem ipsum');

        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->server->set('REQUEST_URI', '/foo.php');
        $app['request_stack']->push($request);

        ob_start();
        $app['logger.exception_handler']->handle($e);
        $output = ob_get_contents();
        ob_end_clean();

        // Symfony\Component\Debug\ExceptionHandler adds a level of output buffering,
        // and this confuses PHPUnit, so reset to initial level.
        while (ob_get_level() > $initialObLevel) {
            ob_end_flush();
        }

        $this->assertContains('<span class="exception_message">Lorem ipsum</span>', $output);
    }

    public function testExceptionConsole()
    {
        $initialObLevel = ob_get_level();

        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());
        $app['monolog.handlers'] = [];
        $app->boot();

        $e = new \Exception('Lorem ipsum');

        $app['console'] = $this->createMock('\Symfony\Component\Console\Application');
        $app['console']
            ->expects($this->once())
            ->method('renderException')
            ->with($e)
            ->willReturnCallback(function() {
                // We must output something; otherwise Symfony\Component\Debug\ExceptionHandler
                // will think that the user exception failed and instead emit its own
                // error message.
                print ' ';
            });

        $app['logger.exception_handler']->handle($e);

        // Symfony\Component\Debug\ExceptionHandler adds a level of output buffering,
        // and this confuses PHPUnit, so reset to initial level.
        while (ob_get_level() > $initialObLevel) {
            ob_end_flush();
        }
    }

    public function testAmqpSkipInfo()
    {
        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());
        $app->register(new \BlackwoodSeven\AmqpService\ServiceProvider());

        $handler = new TestHandler();
        $app['monolog.handler.amqp.wrapped'] = $handler;

        $app['monolog.handlers'] = [$app['monolog.handler.amqp']];

        $app['logger']->info('this is info');
        $app['logger']->warning('this is a warning');

        // Level less than NOTICE is skipped.
        $this->assertCount(1, $handler->getRecords());
        $this->assertTrue($handler->hasRecordThatContains('this is a warning', Logger::WARNING));
    }

    public function testAmqpSkipHttp4xxException()
    {
        $app = new \Silex\Application();
        $app->register(new LogServiceProvider());
        $app->register(new \BlackwoodSeven\AmqpService\ServiceProvider());

        $handler = new TestHandler();
        $app['monolog.handler.amqp.wrapped'] = $handler;

        $app['monolog.handlers'] = [$app['monolog.handler.amqp']];

        $app['logger']->warn('page not found', ['exception' => new HttpException(404, 'page not found')]);
        $app['logger']->warn('server error', ['exception' => new HttpException(500, 'server error')]);

        // 4xx error is ignored, 5xx is not.
        $this->assertCount(1, $handler->getRecords());
        $this->assertTrue($handler->hasRecordThatContains('server error', Logger::WARNING));
    }
}
