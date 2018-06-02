<?php

/**
 * This file is part of twigc.
 *
 * @author  dana <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

use Dana\Twigc\Application;

/**
 * Compile twigc to an executable phar.
 *
 * This clas is heavily inspired by Composer's Compiler:
 *
 * Copyright (c) 2016 Nils Adermann, Jordi Boggiano
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class PharCompiler {
  protected $output;
  protected $baseDir;
  protected $finderSort;

  /**
   * Object constructor.
   *
   * @param OutputInterface $output The output to write to.
   * @param bool $verbose (optional) Whether to display verbose output.
   *
   * @return self
   */
  public function __construct(OutputInterface $output, bool $verbose = false) {
    $this->output     = $output;
    $this->baseDir    = realpath(__DIR__ . '/../..');
    $this->finderSort = function ($a, $b) {
      return strcmp(
        strtr($a->getRealPath(), '\\', '/'),
        strtr($b->getRealPath(), '\\', '/')
      );
    };

    if ( $verbose ) {
      $this->output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
    }
  }

  /**
   * Compiles the project into an executable phar file.
   *
   * @param string $phar
   *   (optional) The path (absolute or relative to the CWD) to write the
   *   resulting phar file to.
   *
   * @return void
   */
  public function compile(string $phar) {
    $this->output->writeln('Compiling phar...');

    $this->output->writeln('', OutputInterface::VERBOSITY_VERBOSE);

    if ( file_exists($phar) ) {
      unlink($phar);
    }

    $obj = new \Phar($phar, 0, basename($phar));
    $obj->setSignatureAlgorithm(\Phar::SHA1);
    $obj->startBuffering();

    $this->output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
    $this->output->writeln('Adding src files...');
    $this->addSrc($obj);

    $this->output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
    $this->output->writeln('Adding vendor files...');
    $this->addVendor($obj);

    $this->output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
    $this->output->writeln('Adding root files...');
    $this->addRoot($obj);

    $this->output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
    $this->output->writeln('Adding bin files...');
    $this->addBin($obj);

    $obj->setStub($this->getStub());
    $obj->stopBuffering();
    unset($obj);

    chmod($phar, 0755);

    $this->output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
    $this->output->writeln("Compiled to ${phar}.");
  }

  /**
   * Add a file to a phar archive.
   *
   * @param \Phar $phar
   *   The phar file to add to.
   *
   * @param \SplFileInfo|string $file
   *   The file to add, or its path.
   *
   * @param bool|null $strip
   *   (optional) Whether to strip extraneous white space from the file in
   *   order to reduce its size. The default is to auto-detect based on file
   *   extension.
   *
   * @return void
   */
  protected function addFile(\Phar $phar, $file, bool $strip = null) {
    if ( is_string($file) ) {
      $file = new \SplFileInfo($file);
    }

    // Strip the absolute base directory off the front of the path
    $prefix = $this->baseDir . DIRECTORY_SEPARATOR;
    $path   = strtr(
      str_replace($prefix, '', $file->getRealPath()),
      '\\',
      '/'
    );

    $this->output->writeln(
      "Adding file: ${path}",
      OutputInterface::VERBOSITY_VERBOSE
    );

    $content = file_get_contents($file);

    // Strip interpreter directives
    if ( strpos($path, 'bin/') === 0 ) {
      $content = preg_replace('%^#!/usr/bin/env php\s*%', '', $content);

    // Replace build-date place-holder
    } elseif ( $path === 'src/Twigc/Application.php' ) {
      $date = new \DateTime('now', new \DateTimeZone('UTC'));

      $content = str_replace(
        '%BUILD_DATE%',
        $date->format('D Y-m-d H:i:s T'),
        $content
      );
    }

    if ( $strip === null ) {
      $strip = in_array($file->getExtension(), ['json', 'lock', 'php'], true);
    }

    if ( $strip ) {
      $content = $this->stripWhiteSpace($content);
    }

    $phar->addFromString($path, $content);
  }

  /**
   * Remove extraneous white space from a string whilst preserving PHP line
   * numbers.
   *
   * @param string $source
   *   The PHP or JSON string to strip white space from.
   *
   * @param string $type
   *   (optional) The type of file the string represents. Available options
   *   are 'php' and 'json'. The default is 'php'.
   *
   * @return string
   */
  protected function stripWhiteSpace(
    string $source,
    string $type = 'php'
  ): string {
    $output = '';

    if ( $type === 'json' ) {
      $output = json_encode(json_decode($json, true));

      return $output === null ? $source : $output . "\n";
    }

    if ( ! function_exists('token_get_all') ) {
      return $source;
    }

    foreach ( token_get_all($source) as $token ) {
      // Arbitrary text, return as-is
      if ( is_string($token) ) {
        $output .= $token;
      // Replace comments by empty lines
      } elseif ( in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT]) ) {
        $output .= str_repeat("\n", substr_count($token[1], "\n"));
      // Collapse and normalise white-space
      } elseif ($token[0] === \T_WHITESPACE) {
        // Collapse consecutive spaces
        $space = preg_replace('/[ \t]+/', ' ', $token[1]);
        // Normalise new-lines to \n
        $space = preg_replace('/(?:\r\n|\r|\n)/', "\n", $space);
        // Trim leading spaces
        $space = preg_replace('/\n[ ]+/', "\n", $space);
        $output .= $space;
      // Anything else, return as-is
      } else {
        $output .= $token[1];
      }
    }

    return $output;
  }

  /**
   * Add files from src directory to a phar.
   *
   * @param \Phar $phar The phar file to add to.
   *
   * @return void
   */
  protected function addSrc(\Phar $phar) {
    $finder = new Finder();
    $finder
      ->files()
      ->in($this->baseDir . '/src')
      ->ignoreDotFiles(true)
      ->ignoreVCS(true)
      ->name('*.php')
      ->notName('PharCompiler.php')
      ->sort($this->finderSort)
    ;

    foreach ( $finder as $file ) {
      $this->addFile($phar, $file);
    }
  }

  /**
   * Adds files from vendor directory to a phar.
   *
   * @param \Phar $phar The phar file to add to.
   *
   * @return void
   */
  protected function addVendor($phar) {
    $devPaths = (new ComposerHelper())->getDevPackages();
    $devPaths = array_map(function ($x) {
      return $x->name . '/';
    }, $devPaths);

    $finder = new Finder();
    $finder
      ->files()
      ->in($this->baseDir . '/vendor')
      ->ignoreDotFiles(true)
      ->ignoreVCS(true)
    ;

    // Exclude files from dev packages
    foreach ( $devPaths as $path ) {
      $finder->notPath($path);
    }

    $finder
      ->exclude('bin')
      ->exclude('doc')
      ->exclude('docs')
      ->exclude('test')
      ->exclude('tests')
      ->notPath('/^[^\/]+\/[^\/]+\/Tests?\//')
      ->notName('*.c')
      ->notName('*.h')
      ->notName('*.m4')
      ->notName('*.w32')
      ->notName('*.xml.dist')
      ->notName('build.xml')
      ->notName('composer.json')
      ->notName('composer.lock')
      ->notName('travis-ci.xml')
      ->notName('phpunit.xml')
      ->notName('ChangeLog*')
      ->notName('CHANGE*')
      ->notName('*CONDUCT*')
      ->notName('CONTRIBUT*')
      ->notName('README*')
      ->sort($this->finderSort)
    ;

    foreach ( $finder as $file ) {
      $this->addFile($phar, $file);
    }
  }

  /**
   * Adds files from project root directory to a phar.
   *
   * @param \Phar $phar The phar file to add to.
   *
   * @return void
   */
  protected function addRoot(\Phar $phar) {
    $this->addFile($phar, $this->baseDir . '/composer.json');
    $this->addFile($phar, $this->baseDir . '/composer.lock');
  }

  /**
   * Adds files from bin directory to a phar.
   *
   * @param \Phar $phar The phar file to add to.
   *
   * @return void
   */
  protected function addBin(\Phar $phar) {
    $this->addFile($phar, $this->baseDir . '/bin/twigc');
  }

  /**
   * Returns the phar stub.
   *
   * @return string
   */
  protected function getStub() {
    $stub = "
      #!/usr/bin/env php
      <?php

      /**
       * This file is part of twigc.
       *
       * @author  dana <dana@dana.is>
       * @license MIT
       */

      \Phar::mapPhar('twigc.phar');
      require 'phar://twigc.phar/bin/twigc';
      __HALT_COMPILER();
    ";
    return preg_replace('/^\s+/m', '', trim($stub)) . "\n";
  }
}
