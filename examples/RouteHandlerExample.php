<?php
namespace MyNamespace;

class Application extends \Silex\Application
{
    use RouteHandlerTrait;

    public function boot()
    {
        // REST
        $this->get('/api/test1', function () {
            return $this->json(['value' => 'test1']);
        });
        $this->get('/api/test2', function () {
            $this->abort(401, "No access");
        });

        $this->get('/web/test1', function () {
            return "TEST1";
        });
        $this->get('/web/test2', function () {
            throw new \Exception('HELLO', 123);
        });

        $this->errorRegex('@^/api/@', [self::class, 'JsonErrorHandler']);

    }
}
