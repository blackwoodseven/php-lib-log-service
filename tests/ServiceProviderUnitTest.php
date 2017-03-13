<?php
namespace BlackwoodSeven\Tests\LogService;

use Pimple\Container;
use BlackwoodSeven\LogService\Provider\LogServiceProvider;

class ServiceProviderUnitTest extends \PHPUnit_Framework_TestCase
{
    public function testRegistration()
    {
        $app = new \Silex\Application();

        $app->register(new \BlackwoodSeven\LogService\Provider\LogServiceProvider());

        $this->assertTrue($app->offsetExists('monolog.handlers'), 'Logger service was not registered');
    }
}
