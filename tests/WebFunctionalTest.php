<?php
namespace BlackwoodSeven\Tests\LogService;

use BlackwoodSeven\LogService\Provider\LogServiceProvider;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebFunctionalTest extends \PHPUnit_Framework_TestCase
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

    protected function makeRequest(string $uri)
    {
        $descriptors = array(
           0 => array('pipe', 'r'), // stdin
           1 => array('pipe', 'w'), // stdout
           2 => array('pipe', 'w'), // stdout
        );
        $environment = [
            'REQUEST_URI' => $uri,
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => getenv('PATH'),
        ];
        $process = proc_open('php-cgi web.php', $descriptors, $pipes, __DIR__ . '/mock', $environment);

        $output = [
            'stdout' => stream_get_contents($pipes[1]),
            'stderr' => stream_get_contents($pipes[2]),
        ];

        list($output['headers'], $output['body']) = explode("\r\n\r\n", $output['stdout']);

        $output['status'] = preg_match('/Status: (\d+)?/', $output['headers'], $matches) ? intval($matches[1]) : 200;

        fclose($pipes[1]);
        fclose($pipes[2]);

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
        $output = $this->makeRequest('/noop');

        $this->assertEquals(200, $output['status']);
        $this->assertEquals('request completed', $output['body']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stdout.log']);
        $this->assertCount(3, $records);
        $this->assertEquals('Matched route "{route}".', $records[0]['message']);

        $this->assertEmpty($output['stderr.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testLogInfoInController()
    {
        $output = $this->makeRequest('/log-info-in-controller');

        $this->assertEquals(200, $output['status']);
        $this->assertEquals('request completed', $output['body']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stdout.log']);
        $this->assertCount(5, $records);
        $this->assertEquals('INFO', $records[0]['level_name']);
        $this->assertEquals($records[2]['message'], 'this is info');
        $this->assertEquals($records[3]['message'], 'this is more info');

        $this->assertEmpty($output['stderr.log']);
        $this->assertEmpty($output['amqp.log']);
    }

    public function testLogWarningInController()
    {
        $output = $this->makeRequest('/log-warn-in-controller');

        $this->assertEquals(200, $output['status']);
        $this->assertEquals('request completed', $output['body']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('WARNING', $records[0]['level_name']);
        $this->assertEquals('this is a warning', $records[0]['message']);

        $this->assertEmpty($output['amqp.log']);
    }

    public function testNoticeInController()
    {
        $output = $this->makeRequest('/notice-in-controller');

        $this->assertEquals(200, $output['status']);
        $this->assertEquals('request completed', $output['body']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('WARNING', $records[0]['level_name']);
        $this->assertEquals('Notice: Undefined variable: undefined', $records[0]['message']);

        $this->assertEmpty($output['amqp.log']);
    }

    public function testWarningInController()
    {
        $output = $this->makeRequest('/warning-in-controller');

        $this->assertEquals(200, $output['status']);
        $this->assertEquals('request completed', $output['body']);
        $this->assertEmpty($output['stderr']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('WARNING', $records[0]['level_name']);
        $this->assertEquals('Warning: fopen() expects at least 2 parameters, 0 given', $records[0]['message']);

        $this->assertEmpty($output['amqp.log']);
    }

    public function testFatalInController()
    {
        $output = $this->makeRequest('/fatal-in-controller');

        $this->assertEquals(500, $output['status']);

        $this->assertContains('Attempted to call function &quot;does_not_exist&quot; from the global namespace', $output['stdout']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(2, $records);
        $this->assertEquals('CRITICAL', $records[0]['level_name']);
        $this->assertContains('Attempted to call function "does_not_exist" from the global namespace', $records[0]['message']);
        $this->assertEquals('Symfony\Component\Debug\Exception\UndefinedFunctionException', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['amqp.log']);
    }

    public function testRecoverableInController()
    {
        $output = $this->makeRequest('/recoverable-in-controller');

        $this->assertEquals(500, $output['status']);

        $this->assertContains('Argument 1 passed to Silex\Application::__construct() must be', $output['stdout']);
        $this->assertContains('<ol class="traces list_exception">', $output['stdout']);

        // FIXME: Why two records?
        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(2, $records);
        $this->assertEquals('CRITICAL', $records[0]['level_name']);
        $this->assertContains('Argument 1 passed to Silex\Application::__construct() must be', $records[0]['message']);
        $this->assertEquals('Symfony\Component\Debug\Exception\FatalThrowableError', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['amqp.log']);
    }

    public function testThrowInController()
    {
        $output = $this->makeRequest('/exception-in-controller');

        $this->assertEquals(500, $output['status']);

        $this->assertContains('throwing in controller', $output['stdout']);
        $this->assertContains('<ol class="traces list_exception">', $output['stdout']);

        $records = $this->getLogRecords($output['stderr.log']);
        $this->assertCount(1, $records);
        $this->assertEquals('CRITICAL', $records[0]['level_name']);
        $this->assertContains('throwing in controller', $records[0]['message']);
        $this->assertEquals('Exception', $records[0]['context']['exception']['class'] ?? null);

        $this->assertEmpty($output['amqp.log']);
    }
}
