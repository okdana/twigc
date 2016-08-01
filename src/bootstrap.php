<?php

/**
 * This file is part of twigc.
 *
 * @author  dana geier <dana@dana.is>
 * @license MIT
 */

/**
 * Helper function for printing an error message.
 *
 * Uses fprintf() to print to STDERR if available; uses echo otherwise.
 *
 * @param string $string
 *   (optional) The message to print. Will be passed through rtrim(); if the
 *   result is an empty string, only an empty line will be printed; otherwise,
 *   the text 'twigc: ' will be appended to the beginning.
 *
 * @return void
 */
function twigc_puts_error($string = '') {
	$string = rtrim($string);
	$string = $string === '' ? '' : "twigc: ${string}";

	// \STDERR only exists when we're using the CLI SAPI
	if ( defined('\\STDERR') ) {
		fprintf(\STDERR, "%s\n", $string);
	} else {
		echo $string, "\n";
	}
}

// Find Composer's auto-loader
$autoloaders = [
	// Phar/repo path
	__DIR__ . '/../vendor/autoload.php',
	// Composer path
	__DIR__ . '/../../../autoload.php',
];

foreach ( $autoloaders as $autoloader ) {
	if ( file_exists($autoloader) ) {
		define('TWIGC_AUTOLOADER', $autoloader);
		break;
	}
}

unset($autoloaders, $autoloader);

// Disallow running from non-CLI SAPIs
if ( \PHP_SAPI !== 'cli' ) {
	twigc_puts_error("This tool must be invoked via PHP's CLI SAPI.");
	exit(1);
}

// Give a meaningful error if we don't have the auto-loader
if ( ! defined('TWIGC_AUTOLOADER') ) {
	twigc_puts_error('Auto-loader is missing — try running `composer install`.');
	exit(1);
}

require_once TWIGC_AUTOLOADER;

