<?php

namespace BlackwoodSeven\LogService;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

trait RouteHandlerTrait
{

    public function beforeRegex($pattern, $callback, $priority = 0)
    {
        $callback = $this['callback_resolver']->resolveCallback($callback);
        $this->before(function (Request $request, Application $app) use ($pattern, $callback) {
            if (preg_match($pattern, $request->getPathInfo())) {
                return call_user_func($callback, $request, $app);
            }
        }, $priority);
    }

    public function afterRegex($pattern, $callback, $priority = 0)
    {
        $callback = $this['callback_resolver']->resolveCallback($callback);
        $this->after(function (Request $request, Application $app) use ($pattern, $callback) {
            if (preg_match($pattern, $request->getPathInfo())) {
                return call_user_func($callback, $request, $app);
            }
        }, $priority);
    }

    public function errorRegex($pattern, $callback, $priority = -8)
    {
        $callback = $this['callback_resolver']->resolveCallback($callback);
        $this->error(function (\Exception $e, Request $request, $code) use ($pattern, $callback) {
            if (preg_match($pattern, $request->getPathInfo())) {
                return call_user_func($callback, $e, $request, $code, $this);
            }
        }, $priority);
    }

    static public function JsonErrorHandler(\Exception $e, Request $request, $code, \Silex\Application $app)
    {
        return $app->json([
            'title' => $e->getMessage(),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => (string) $e,
        ], $code, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
