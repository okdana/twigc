<?php

/**
 * This file is part of twigc.
 *
 * @author  dana <dana@dana.is>
 * @license MIT
 */

/**
 * Print an error message.
 *
 * Uses fprintf() to print to stderr if available; uses echo otherwise.
 *
 * @param string $string
 *   (optional) The message to print. Is passed through rtrim(); if the result
 *   is an empty string, only an empty line is printed; otherwise, the text
 *   'twigc: ' is appended to the beginning.
 *
 * @return void
 */
function twigc_puts_error($string = '') {
  $string = rtrim($string);
  $string = $string === '' ? '' : "twigc: ${string}";

  if ( defined('\\STDERR') ) {
    fprintf(\STDERR, "%s\n", $string);
  } else {
    echo $string, "\n";
  }
}

if ( \PHP_SAPI !== 'cli' ) {
  twigc_puts_error("This tool must be invoked via PHP's CLI SAPI.");
  exit(1);
}

(function () {
  $paths = [
    // Phar/repo path
    __DIR__ . '/../vendor/autoload.php',
    // Composer path
    __DIR__ . '/../../../autoload.php',
  ];

  foreach ( $paths as $path ) {
    if ( file_exists($path) ) {
      define('TWIGC_AUTOLOADER', $path);
      return;
    }
  }
})();

if ( ! defined('\\TWIGC_AUTOLOADER') ) {
  twigc_puts_error('Auto-loader is missing — try running `composer install`.');
  exit(1);
}

require_once \TWIGC_AUTOLOADER;
