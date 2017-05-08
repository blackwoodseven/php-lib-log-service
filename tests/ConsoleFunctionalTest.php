<?php
namespace BlackwoodSeven\Tests\LogService;

use BlackwoodSeven\LogService\Provider\LogServiceProvider;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConsoleFunctionalTest extends \PHPUnit_Framework_TestCase
{
    protected $logDir;

    protected $logFiles = ['stderr.log', 'stdout.log', 'amqp.log'];

    protected function setUp()
    {
        $this->logDir = sys_get_temp_dir();

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir);
        }

        foreach ($this->logFiles as $file) {
            $path = $this->logDir . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    protected function runCommand(string $action)
    {
        $descriptors = array(
           0 => array('pipe', 'r'), // stdin
           1 => array('pipe', 'w'), // stdout
           2 => array('pipe', 'w'), // stdout
        );
        $process = proc_open('php console.php foo ' . $action, $descriptors, $pipes, __DIR__ . '/mock');

        $output = [
            'stdout' => stream_get_contents($pipes[1]),
            'stderr' => stream_get_contents($pipes[2]),
        ];

        fclose($pipes[1]);
        fclose($pipes[2]);

        $output['status'] = proc_close($process);

        foreach ($this->logFiles as $file) {
            $path = $this->logDir . '/' . $file;
            $output[$file] = file_exists($path) ? file_get_contents($path) : '';
        }

        return $output;
    }

    /**
     * @param  string $data  The entire contents of a JSON-encoded log file
     * @return array  Array of stdClass objects representing
     */
    protected function getLogRecords(string $data)
    {
        $data = rtrim($data);
        if (!$data) {
            return [];
        }
        $lines = explode("\n", $data);
        return array_map(function($json) { return json_decode($json, true); }, $lines);
    }

    public function testNoop()
    {
        $output = $this->runCommand('noop');

        $this->assertEquals(0, $output['status']);
        $this->assertEquals('execute() completed', $output['stdout']);
        $this->assertEmpty($output['stderr']);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['stderr.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testLogInfoInExecute()
    {
        $output = $this->runCommand('log-info-in-execute');

        $this->assertEquals(0, $output['status']);
        $this->assertEquals('execute() completed', $output['stdout']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stdout.log']);
        $this->assertCount(2, $records);
        $this->assertEquals('INFO', $records[0]['level_name']);
        $this->assertEquals($records[0]['message'], 'this is info');
        $this->assertEquals($records[1]['message'], 'this is more info');

        $this->assertEmpty($output['stderr.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testLogWarningInExecute()
    {
        $output = $this->runCommand('log-warn-in-execute');

        $this->assertEquals(0, $output['status']);
        $this->assertEquals('execute() completed', $output['stdout']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('WARNING', $records[0]['level_name']);
        $this->assertEquals('this is a warning', $records[0]['message']);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testNoticeInExecute()
    {
        $output = $this->runCommand('notice-in-execute');

        $this->assertEquals(0, $output['status']);
        $this->assertEquals('execute() completed', $output['stdout']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('WARNING', $records[0]['level_name']);
        $this->assertEquals('Notice: Undefined variable: undefined', $records[0]['message']);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testWarningInExecute()
    {
        $output = $this->runCommand('warning-in-execute');

        $this->assertEquals(0, $output['status']);
        $this->assertEquals('execute() completed', $output['stdout']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('WARNING', $records[0]['level_name']);
        $this->assertEquals('Warning: fopen() expects at least 2 parameters, 0 given', $records[0]['message']);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testFatalInExecute()
    {
        $output = $this->runCommand('fatal-in-execute');

        $this->assertNotEquals(0, $output['status']);

        $this->assertContains('Attempted to call function "does_not_exist" from the global namespace', $output['stdout']);
        $this->assertContains('Exception trace', $output['stdout']);
        $this->assertContains("\x1B[39;49m", $output['stdout']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('ERROR', $records[0]['level_name']);
        $this->assertContains('Attempted to call function "does_not_exist" from the global namespace', $records[0]['message']);
        $this->assertEquals('Symfony\Component\Debug\Exception\UndefinedFunctionException', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testRecoverableInExecute()
    {
        $output = $this->runCommand('recoverable-in-execute');

        $this->assertNotEquals(0, $output['status']);

        $this->assertContains('Argument 1 passed to FooCommand::__construct() must be an', $output['stdout']);
        $this->assertContains('Exception trace', $output['stdout']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('ERROR', $records[0]['level_name']);
        $this->assertContains('Argument 1 passed to FooCommand::__construct() must be an', $records[0]['message']);
        $this->assertEquals('Symfony\Component\Debug\Exception\FatalThrowableError', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testThrowInExecute()
    {
        $output = $this->runCommand('throw-in-configure');

        $this->assertNotEquals(0, $output['status']);

        $this->assertContains('throwing in configure', $output['stdout']);
        $this->assertContains('Exception trace', $output['stdout']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        // FIXME: Shold be CRITICAL.
        $this->assertEquals('ERROR', $records[0]['level_name']);
        $this->assertContains('throwing in configure', $records[0]['message']);
        $this->assertEquals('Exception', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testThrowInConfigure()
    {
        $output = $this->runCommand('throw-in-execute');

        $this->assertNotEquals(0, $output['status']);

        $this->assertContains('throwing in execute', $output['stdout']);
        $this->assertContains('Exception trace', $output['stdout']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('ERROR', $records[0]['level_name']);
        $this->assertContains('throwing in execute', $records[0]['message']);
        $this->assertEquals('Exception', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['stdout.log']);
        $this->assertEmpty($output['amqp.log']);
    }
}
