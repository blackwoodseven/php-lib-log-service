<?php
namespace MyNamespace;

class Application extends \Silex\Application
{
    public function boot()
    {
        $this->register(new Provider\LogServiceProvider());

        parent::boot();
    }
}
