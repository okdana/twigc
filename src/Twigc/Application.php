<?php

/**
 * This file is part of twigc.
 *
 * @author  dana <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

use GetOpt\{Argument,GetOpt,Operand,Option};
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\{ConsoleOutputInterface,OutputInterface};
use Twig\Environment;
use Twig\Loader\{ArrayLoader,FilesystemLoader};

use Dana\Twigc\ComposerHelper;

/**
 * This class represents the entire `twigc` application.
 *
 * To be completely honest, this feels really shitty to me, and i don't like it.
 * But after eliminating the standard Symfony\Console structure (due to
 * Console's woefully inadequate argument handling, amongst other things), i
 * find myself unsure of the best way to structure this, especially given how
 * simple the application is, and just kind of want to be done with it. I guess
 * this works for now, but i would welcome any improvements.
 */
class Application {
  const NAME       = 'twigc';
  const VERSION    = '0.3.0';
  const BUILD_DATE = '%BUILD_DATE%'; // Replaced during build

  protected $name;
  protected $version;

  /**
   * Construct the object.
   *
   * @param string|null $name
   *   (optional) The name of the application, to be used in error messages and
   *   the like.
   *
   * @param string|null $version
   *   (optional) The version number of the application, to be used in the
   *   `--version` output.
   *
   * @return self
   */
  public function __construct(string $name = null, string $version = null) {
    $this->name    = $name    ?? static::NAME;
    $this->version = $version ?? static::VERSION;
  }

  /**
   * Run the application.
   *
   * This is mostly a wrapper around doRun() to handle error printing.
   *
   * @param OutputInterface $output
   *   The output to write to.
   *
   * @param array|null $argv
   *   (optional) Command-line arguments to the application (with the 0th member
   *   as the application name).
   *
   * @return int
   */
  public function run(OutputInterface $output, array $argv = null): int {
    if ( $output instanceof ConsoleOutputInterface ) {
      $error = $output->getErrorOutput();
    } else {
      $error = $output;
    }

    try {
      return $this->doRun($output, $argv);
    } catch ( \Exception $e ) {
      $error->writeln(sprintf(
        '%s: %s', $this->name,
        rtrim($e->getMessage(), "\r\n"))
      );
      return 1;
    }
  }

  /**
   * Run the application (for real).
   *
   * @param OutputInterface $output
   *   The output to write to.
   *
   * @param array|null $argv
   *   (optional) Command-line arguments to the application (with the 0th member
   *   as the application name).
   *
   * @return int
   */
  public function doRun(OutputInterface $output, array $argv = null): int {
    $argv   = $argv ?? $_SERVER['argv'];
    $getopt = $this->getGetOpt();

    $getopt->process(array_slice($argv, 1));

    if ( $getopt->getOption('help') ) {
      $this->doHelp($output, $getopt);
      return 0;
    }
    if ( $getopt->getOption('version') ) {
      $this->doVersion($output);
      return 0;
    }
    if ( $getopt->getOption('credits') ) {
      $this->doCredits($output);
      return 0;
    }

    $inputData = [];
    $template  = $getopt->getOperand('template');
    $dirs      = $getopt->getOption('dir');
    $temp      = false;

    // If we're receiving data on standard input, and we didn't get a template,
    // assume `-` — we'll make sure this doesn't conflict with `-j` below
    if ( ! posix_isatty(\STDIN) ) {
      $template = $template ?? '-';
    }

    // Add the template's parent directory if we're not using standard input
    if ( ($template ?? '-') !== '-' ) {
      $dirs = array_merge([dirname($template)], $dirs);
    }

    if ( $template === null ) {
      $this->doHelp($output, $getopt, 'No template specified');
      return 1;
    }

    // Input data via environment
    if ( $getopt->getOption('env') ) {
      if ( empty($_ENV) && strpos(ini_get('variables_order'), 'E') === false ) {
        throw new \RuntimeException(
          "INI setting 'variables_order' must include 'E' to use option 'env'"
        );
      }
      $inputData = array_merge($inputData, $_ENV);
    }

    // Input data via query string
    foreach ( $getopt->getOption('query') as $query ) {
      $query  = ltrim($query, '?');
      $parsed = [];

      parse_str($query, $parsed);

      $inputData = array_merge($inputData, $parsed);
    }

    // Input data via JSON
    foreach ( $getopt->getOption('json') as $json ) {
      // JSON supplied via standard input
      if ( $json === '-' ) {
        if ( $template === '-' ) {
          throw new \InvalidArgumentException(
            'Can not read both template and JSON input from stdin'
          );
        }

        $json = file_get_contents('php://stdin');

      // JSON supplied via file
      } elseif ( (ltrim($json)[0] ?? '') !== '{' ) {
        if ( ! file_exists($json) || is_dir($json) ) {
          throw new \InvalidArgumentException(
            "Missing or invalid JSON file: ${json}"
          );
        }
        $json = file_get_contents($json);
      }

      // This check is here to prevent errors if the input is just empty
      if ( trim($json) !== '' ) {
        $json = json_decode($json, true);
      }

      if ( ! is_array($json) ) {
        throw new \InvalidArgumentException(
          'JSON input must be a dictionary'
        );
      }

      $inputData = array_merge($inputData, $json);
    }

    // Input data via key=value pair
    foreach ( $getopt->getOption('pair') as $pair ) {
      $kv = explode('=', $pair, 2);

      if ( count($kv) !== 2 ) {
        throw new \InvalidArgumentException(
          "Illegal key=value pair: ${pair}"
        );
      }

      $inputData[$kv[0]] = $kv[1];
    }

    // Template supplied via file path
    if ( $template !== '-' ) {
      $loader = new FilesystemLoader($dirs);
    // Template supplied via standard input
    } else {
      // If we've been supplied one or more search directories, we'll need to
      // write the template out to a temp directory so we can use the file-
      // system loader
      if ( $dirs ) {
        $temp     = true;
        $template = implode('/', [
          sys_get_temp_dir(),
          implode('.', ['', $this->name, getmypid(), md5(time())]),
          $template,
        ]);

        mkdir(dirname($template));
        file_put_contents($template, file_get_contents('php://stdin'), \LOCK_EX);

        $dirs   = array_merge([dirname($template)], $dirs);
        $loader = new FilesystemLoader($dirs);

      // Otherwise, we can just use the array loader, which is a little faster
      // and cleaner
      } else {
        $loader = new ArrayLoader([
          $template => file_get_contents('php://stdin'),
        ]);
      }
    }

    // Render
    try {
      $twig = new Environment($loader, [
        'cache'            => $getopt->getOption('cache') ?? false,
        'debug'            => false,
        'strict_variables' => (bool) $getopt->getOption('strict'),
        'autoescape'       => $this->getEscaper(
          $getopt->getOption('escape'),
          $template
        ),
      ]);

      $twig->getExtension('Twig_Extension_Core')->setEscaper(
        'json',
        function($twigEnv, $string, $charset) {
          return json_encode(
            $string,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
          );
        }
      );
      $twig->getExtension('Twig_Extension_Core')->setEscaper(
        'sh',
        function($twigEnv, $string, $charset) {
          return '"' . addcslashes($string, '$`\\"') . '"';
        }
      );

      $output->writeln(
        rtrim($twig->render(basename($template), $inputData), "\r\n")
      );

    // Clean up
    } finally {
      if ( $temp ) {
        unlink($template);
        rmdir(dirname($template));
      }
    }

    return 0;
  }

  /**
   * Display the application's usage help.
   *
   * @param OutputInterface $output
   *   The output to write to.
   *
   * @param GetOpt $getopt
   *   The GetOpt instance from which to derive the usage help.
   *
   * @param string|null $message
   *   (optional) An additional message to print above the usage help. This is
   *   intended primarily for error messages.
   *
   * @return int
   */
  public function doHelp(
    OutputInterface $output,
    GetOpt $getopt,
    string $message = null
  ): int {
    if ( $message !== null && $message !== '' ) {
      $output->writeln("{$this->name}: " . rtrim($message, "\r\n") . "\n");
    }
    $output->writeln(rtrim($getopt->getHelpText(), "\r\n"));
    return 0;
  }

  /**
   * Display the application's version information.
   *
   * @param OutputInterface $output The output to write to.
   *
   * @return int
   */
  public function doVersion(OutputInterface $output): int {
    $version = sprintf('%s version %s', $this->name, $this->version);

    if ( strpos(static::BUILD_DATE, '%') === false ) {
      $version .= sprintf(' (built %s)', static::BUILD_DATE);
    }

    $output->writeln($version);
    return 0;
  }

  /**
   * Display the application's dependency information.
   *
   * @param OutputInterface $output The output to write to.
   *
   * @return int
   */
  public function doCredits(OutputInterface $output): int {
    $packages = (new ComposerHelper())->getPackages();

    $table = new Table($output);
    $table->setStyle('compact');
    $table->getStyle()->setVerticalBorderChar('');
    $table->getStyle()->setCellRowContentFormat('%s  ');
    $table->setHeaders(['name', 'version', 'licence']);

    foreach ( $packages as $package ) {
      $table->addRow([
        $package->name,
        ltrim($package->version, 'v'),
        implode(', ', $package->license) ?: '?',
      ]);
    }
    $table->render();

    return 0;
  }

  /**
   * Get an instance of GetOpt configured for this application.
   *
   * @return GetOpt
   */
  public function getGetOpt(): GetOpt {
    $getopt = new GetOpt(null, [
      GetOpt::SETTING_SCRIPT_NAME     => $this->name,
      GetOpt::SETTING_STRICT_OPERANDS => true,
    ]);
    $getopt->addOptions([
      Option::create('h', 'help', GetOpt::NO_ARGUMENT)
        ->setDescription('Display this usage help and exit')
      ,
      Option::create('V', 'version', GetOpt::NO_ARGUMENT)
        ->setDescription('Display version information and exit')
      ,
      Option::create(null, 'credits', GetOpt::NO_ARGUMENT)
        ->setDescription('Display dependency information and exit')
      ,
      Option::create(null, 'cache', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Enable caching to specified directory')
        ->setArgumentName('dir')
        ->setValidation('is_dir')
      ,
      Option::create('d', 'dir', GetOpt::MULTIPLE_ARGUMENT)
        ->setDescription('Add specified search directory to loader')
        ->setArgumentName('dir')
        ->setValidation('is_dir')
      ,
      Option::create('e', 'escape', GetOpt::REQUIRED_ARGUMENT)
        ->setArgumentName('strategy')
        ->setDescription('Specify default auto-escaping strategy')
      ,
      Option::create('E', 'env', GetOpt::NO_ARGUMENT)
        ->setDescription('Derive input data from environment')
      ,
      Option::create('j', 'json', GetOpt::MULTIPLE_ARGUMENT)
        ->setArgumentName('dict/file')
        ->setDescription('Derive input data from specified JSON file or dictionary string')
      ,
      Option::create('p', 'pair', GetOpt::MULTIPLE_ARGUMENT)
        ->setArgumentName('input')
        ->setDescription('Derive input data from specified key=value pair')
      ,
      Option::create(null, 'query', GetOpt::MULTIPLE_ARGUMENT)
        ->setArgumentName('input')
        ->setDescription('Derive input data from specified URL query string')
      ,
      Option::create('s', 'strict', GetOpt::NO_ARGUMENT)
        ->setDescription('Throw exception when undefined variable is referenced')
      ,
    ]);
    $getopt->addOperands([
      Operand::create('template', Operand::OPTIONAL),
    ]);

    return $getopt;
  }

  /**
   * Get the correct Twig escape method given the provided options.
   *
   * @param string|null $escape
   *   The user-provided escape option, or null if not provided.
   *
   * @param string|null $template
   *   The name/path of the template file, or null if not provided. This is only
   *   used when $escape is null or 'auto'.
   *
   * @return string|false
   */
  public function getEscaper($escape, string $template = null) {
    $escape   = $escape === null ? $escape : strtolower($escape);
    $template = $template ?? '';

    if ( $escape === null || $escape === 'auto' ) {
      if (
        substr($template, -5) === '.twig'
        &&
        strpos(substr($template, 0, -5), '.')
      ) {
        $ext = pathinfo(substr($template, 0, -5), \PATHINFO_EXTENSION);
      } else {
        $ext = pathinfo($template, \PATHINFO_EXTENSION);
      }

      switch ( strtolower($ext) ) {
        case 'htm':
        case 'html':
        case 'phtml':
        case 'thtml':
        case 'xhtml':
        case 'template':
        case 'tmpl':
        case 'tpl':
          return 'html';
        case 'css':
        case 'scss':
          return 'css';
        case 'js':
          return 'js';
        case 'json':
          return 'json';
        case 'bash':
        case 'ksh':
        case 'sh':
        case 'zsh':
          return 'sh';
      }

      return false;
    }

    // Otherwise, try to parse the supplied method
    switch ( $escape ) {
      case 'f':
      case 'n':
      case 'none':
      case 'never':
        $escape = 'false';
        break;
      case 't':
      case 'y':
      case 'always':
        $escape = 'true';
        break;
    }

    $bool = filter_var(
      $escape,
      \FILTER_VALIDATE_BOOLEAN,
      \FILTER_NULL_ON_FAILURE
    );

    if ( $bool !== null ) {
      $escape = $bool ? 'html' : false;
    }

    return $escape;
  }
}
