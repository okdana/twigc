<?php

/**
 * This file is part of twigc.
 *
 * @author  dana <dana@dana.is>
 * @license MIT
 */

namespace Dana\Test\Twigc;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

use Dana\Twigc\Application;

/**
 * Tests for twigc.
 *
 * This doesn't feel very 'DRY'. Would like to improve it somehow.
 */
class ApplicationTest extends TestCase {
  protected $output;
  protected $app;
  protected $template;
  protected $tempDir;
  protected $tempFiles = [];

  /**
   * Set up before tests.
   *
   * @return void
   */
  protected function setUp() {
    $this->output   = new BufferedOutput();
    $this->app      = new Application();
    $this->template = $this->makeFile('default', 'testEnv: {{ testEnv }}');
  }

  /**
   * Tear down after tests.
   *
   * @return void
   */
  protected function tearDown() {
    $this->output->fetch();

    // This is slow, but i'm too lazy to handle recursive deletion properly
    if ( ! empty($this->tempFiles) ) {
      $dir = escapeshellarg($this->tempDir);
      exec("rm -rf ${dir}/?* 2> /dev/null");
      $this->tempFiles = [];
    }
  }

  /**
   * Create a temporary file, and optionally populate it with data.
   *
   * @param string $name
   *   The temporary file name/suffix. If the name contains an internal slash,
   *   all leading directories are created.
   *
   * @param string|null $content
   *   (optional) Any data to populate the file with.
   *
   * @return string The name of the created file.
   */
  protected function makeFile(string $name, string $data = null): string {
    // Create our temp directory if we don't already have it
    if ( ! $this->tempDir ) {
      $rand = base64_encode(random_bytes(32));
      $rand = substr(str_replace(['/', '+', '='], '', $rand), 0, 10);
      $this->tempDir  = sys_get_temp_dir();
      $this->tempDir .= "/Dana.Test.Twigc.ApplicationTest.${rand}";

      if ( ! is_dir($this->tempDir) ) {
        mkdir($this->tempDir, 0700);
      }
    }

    $dir  = $this->tempDir;
    $name = trim($name, '/.');

    if ( strlen($name) === 0 ) {
      throw \RuntimeException('Expected file name');
    }

    if ( strpos($name, '/') !== false ) {
      $dir .= '/' . dirname($name);

      if ( ! is_dir($dir) ) {
        mkdir($dir, 0700, true);
      }

      $name = basename($name);
    }

    $file = "${dir}/${name}";
    $this->tempFiles[] = $file;

    if ( $data !== null ) {
      if ( file_put_contents($file, $data, \LOCK_EX) === false ) {
        throw \RuntimeException("Write failed: ${file}");
      }
    } elseif ( touch($file) === false ) {
      throw \RuntimeException("Write failed: ${file}");
    }

    return $file;
  }

  /**
   * Run the application and return common test data.
   *
   * @param $args
   *   (optional) Zero or more arguments to pass to the application. This should
   *   NOT include argv[0].
   *
   * @return array
   *   An array containing the application return status as an integer, the raw
   *   output as a string, and the output as an array of lines.
   */
  protected function runApp(...$args) {
    $argv = ['', '-e', 'none'];

    if ( is_array($args[0]) ) {
      $argv = array_merge($argv, $args[0]);
    } else {
      $argv = array_merge($argv, $args);
    }

    $argv = array_filter($argv, function ($v) {
      return $v !== null;
    });

    $ret    = $this->app->run($this->output, $argv);
    $buffer = $this->output->fetch();
    $lines  = explode("\n", rtrim($buffer, "\r\n"));

    return [$ret, $buffer, $lines];
  }

  /**
   * Provide data for testEscape().
   *
   * All tests assume the following input data (value is literal):
   *
   *   testEnv="<foo$bar>"
   *
   * All tests assume the following template:
   *
   *   testEnv: {{ testEnv }}
   *
   * @return array[]
   */
  public function provideTestEscape() {
    return [
      // Escape method: none
      ['f',     'testEnv: "<foo$bar>"'],
      ['false', 'testEnv: "<foo$bar>"'],
      ['n',     'testEnv: "<foo$bar>"'],
      ['no',    'testEnv: "<foo$bar>"'],
      ['none',  'testEnv: "<foo$bar>"'],
      ['never', 'testEnv: "<foo$bar>"'],

      // Escape method: html
      ['always', 'testEnv: &quot;&lt;foo$bar&gt;&quot;'],
      ['t',      'testEnv: &quot;&lt;foo$bar&gt;&quot;'],
      ['true',   'testEnv: &quot;&lt;foo$bar&gt;&quot;'],
      ['y',      'testEnv: &quot;&lt;foo$bar&gt;&quot;'],
      ['yes',    'testEnv: &quot;&lt;foo$bar&gt;&quot;'],
      ['html',   'testEnv: &quot;&lt;foo$bar&gt;&quot;'],

      // Escape method: css
      ['css', 'testEnv: \\22 \\3C foo\\24 bar\\3E \\22'],

      // Escape method: html_attr
      ['html_attr', 'testEnv: &quot;&lt;foo&#x24;bar&gt;&quot;'],

      // Escape method: js
      ['js', 'testEnv: \\x22\\x3Cfoo\\x24bar\\x3E\\x22'],

      // Escape method: json
      ['json', 'testEnv: "\"<foo$bar>\""'],

      // Escape method: sh
      ['sh', 'testEnv: "\"<foo\$bar>\""'],

      // Escape method: url
      ['url', 'testEnv: %22%3Cfoo%24bar%3E%22'],
    ];
  }

  /**
   * Test `-h` / `--help` function.
   *
   * @return void
   */
  public function testHelp() {
    foreach ( ['-h', '--help'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp($opt);

      $this->assertSame(0, $ret);
      $this->assertGreaterThan(3, count($lines));
      $this->assertContains('--help', $buffer);
      $this->assertContains('--version', $buffer);
    }
  }

  /**
   * Test `-V` / `--version` function.
   *
   * @return void
   */
  public function testVersion() {
    foreach ( ['-V', '--version'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp($opt);

      $this->assertSame(0, $ret);
      $this->assertSame(1, count($lines));
      $this->assertContains(' version ', $lines[0]);
    }
  }

  /**
   * Test `--credits` function.
   *
   * @return void
   */
  public function testCredits() {
    list($ret, $buffer, $lines) = $this->runApp('--credits');

    $this->assertSame(0, $ret);
    $this->assertGreaterThan(1, count($lines));
    $this->assertContains('licence', $lines[0]);
  }

  /**
   * Test `-d` / `--dir` function, as well as default include-directory
   * functionality.
   *
   * @return void
   */
  public function testDir() {
    $templateSame = $this->makeFile('dir1/a.twig', '{% include "b.twig" %}');
    $includeSame  = $this->makeFile('dir1/b.twig', 'included: {{ testEnv }}');

    $templateDiff = $this->makeFile('dir2/a.twig', '{% include "b.twig" %}');
    $includeDiff  = $this->makeFile('dir3/b.twig', 'included: {{ testEnv }}');

    // $includeSame's directory should be searched by default
    list($ret, $buffer, $lines) = $this->runApp(
      '-p',
      'testEnv=abc123',
      $templateSame
    );
    $this->assertSame(0, $ret);

    // $includeDiff's directory should have to be specified manually
    list($ret, $buffer, $lines) = $this->runApp(
      '-p',
      'testEnv=abc123',
      $templateDiff
    );
    $this->assertNotSame(0, $ret);

    // Now we confirm that that works
    foreach ( ['-d', '--dir'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp(
        $opt,
        dirname($includeDiff),
        '-p',
        'testEnv=abc123',
        $templateDiff
      );

      $this->assertSame(0, $ret);
      $this->assertSame(1, count($lines));
      $this->assertContains('included: abc123', $lines[0]);
    }
  }

  /**
   * Test `-E` / `--env` function.
   *
   * @return void
   */
  public function testEnv() {
    foreach ( ['-E', '--env'] as $opt ) {
      // This option can't be set at run time; we'll just test what we have
      if ( strpos(ini_get('variables_order'), 'E') === false ) {
        list($ret, $buffer, $lines) = $this->runApp($opt, $this->template);

        $this->assertGreaterThan(0, $ret);
        $this->assertContains('variables_order', $buffer);
        return;
      }

      $_ENV['testEnv'] = 'abc123';
      list($ret, $buffer, $lines) = $this->runApp($opt, $this->template);

      $this->assertSame(0, $ret);
      $this->assertSame(1, count($lines));
      $this->assertContains('testEnv: abc123', $lines[0]);
    }
  }

  /**
   * Test `-j` / `--json` function (dictionary string).
   *
   * @return void
   */
  public function testJsonDict() {
    foreach ( ['-j', '--json'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp(
        $opt,
        '{"testEnv": "abc123"}',
        $this->template
      );

      $this->assertSame(0, $ret);
      $this->assertSame(1, count($lines));
      $this->assertContains('testEnv: abc123', $lines[0]);
    }
  }

  /**
   * Test `-j` / `--json` function (file).
   *
   * @return void
   */
  public function testJsonFile() {
    $jsonFile = $this->makeFile('json', '{"testEnv": "abc123"}');

    foreach ( ['-j', '--json'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp(
        $opt,
        $jsonFile,
        $this->template
      );

      $this->assertSame(0, $ret);
      $this->assertSame(1, count($lines));
      $this->assertContains('testEnv: abc123', $lines[0]);
    }
  }

  /**
   * Test `-p` / `--pair` function.
   *
   * @return void
   */
  public function testPair() {
    foreach ( ['-p', '--pair'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp(
        $opt,
        'testEnv=abc123',
        $this->template
      );

      $this->assertSame(0, $ret);
      $this->assertSame(1, count($lines));
      $this->assertContains('testEnv: abc123', $lines[0]);
    }
  }

  /**
   * Test `--query` function.
   *
   * @return void
   */
  public function testQuery() {
    list($ret, $buffer, $lines) = $this->runApp(
      '--query',
      '?testEnv=abc123&testEnv2=x&testEnv3=y',
      $this->template
    );

    $this->assertSame(0, $ret);
    $this->assertSame(1, count($lines));
    $this->assertContains('testEnv: abc123', $lines[0]);
  }

  /**
   * Test input-data precedence.
   *
   * Input precedence should be as follows (ascending):
   *
   *   env -> query -> json -> pair
   *
   * @return void
   */
  public function testInputDataPrecedence() {
    list($ret, $buffer, $lines) = $this->runApp(
      '--pair',
      'testEnv=aaa',
      '--query',
      '?testEnv=bbb',
      '--json',
      '{ "testEnv": "ccc" }',
      $this->template
    );

    $this->assertSame(0, $ret);
    $this->assertSame(1, count($lines));
    $this->assertContains('testEnv: aaa', $lines[0]);
  }

  /**
   * Test handling of undefined variable WITHOUT `-s` / `--strict`.
   *
   * @return void
   */
  public function testUndefinedNoStrict() {
    list($ret, $buffer, $lines) = $this->runApp($this->template);

    $this->assertSame(0, $ret);
    $this->assertSame(1, count($lines));
    $this->assertContains('testEnv:', $lines[0]);
    $this->assertNotContains('abc123', $lines[0]);
  }

  /**
   * Test handling of undefined variable WITHOUT `-s` / `--strict`.
   *
   * @return void
   */
  public function testUndefinedStrict() {
    foreach ( ['-s', '--strict'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp($opt, $this->template);

      $this->assertSame(1, $ret);
      $this->assertContains('testEnv', $lines[0]);
      $this->assertNotContains('abc123', $lines[0]);
    }
  }

  /**
   * Test various escape methods with `-e` / `--escape`.
   *
   * @param string $method The method to test.
   * @param string $expected The expected output.
   *
   * @dataProvider provideTestEscape
   *
   * @return void
   */
  public function testEcape(string $method, string $expected) {
    foreach ( ['-e', '--escape'] as $opt ) {
      list($ret, $buffer, $lines) = $this->runApp(
        $opt,
        $method,
        '-p',
        'testEnv="<foo$bar>"',
        $this->template
      );

      $this->assertSame(0, $ret);
      $this->assertContains($expected, $lines[0] ?? '');
    }
  }
}
