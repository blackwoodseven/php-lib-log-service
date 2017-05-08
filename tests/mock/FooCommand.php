<?php

use Silex\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FooCommand extends Command
{
    protected $app;

    public function __construct(Application $app)
    {
        parent::__construct();
        $this->app = $app;
    }

    protected function configure()
    {
        if (in_array('throw-in-configure', $GLOBALS['argv'])) {
            throw new \Exception('throwing in configure');
        }

        $this->setName('foo')
            ->addArgument('action');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        switch ($action) {
            case 'noop':
                break;
            case 'log-info-in-execute':
                $this->app['logger']->info('this is info');
                $this->app['logger']->info('this is more info');
                break;

            case 'log-warn-in-execute':
                $this->app['logger']->warn('this is a warning');
                break;

            case 'notice-in-execute':
                $foo = $undefined;
                break;

            case 'warning-in-execute':
                fopen();
                break;

            case 'fatal-in-execute':
                does_not_exist();
                break;

            case 'recoverable-in-execute':
                new FooCommand('invalid');
                break;

            case 'throw-in-execute':
                throw new \Exception('throwing in execute');

            default:
                throw new \Exception('Invalid action: ' . $action);
        }

        echo "execute() completed";
    }
}

