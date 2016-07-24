<?php

/**
 * This file is part of twigc.
 *
 * @author  dana geier <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Compiles twigc into an executable phar file.
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
	protected $versionNumber;
	protected $versionCommit;
	protected $versionDate;

	/**
	 * Object constructor.
	 *
	 * @param (bool) $verbose (optional) Whether to display verbose output.
	 *
	 * @return self
	 */
	public function __construct($verbose = false) {
		$this->output     = new ConsoleOutput();
		$this->baseDir    = realpath(\Dana\Twigc\Twigc::BASE_DIR);
		$this->finderSort = function ($a, $b) {
			return strcmp(
				strtr($a->getRealPath(), '\\', '/'),
				strtr($b->getRealPath(), '\\', '/')
			);
		};

		if ( $verbose ) {
			$this->output->setVerbosity(ConsoleOutput::VERBOSITY_VERBOSE);
		}
	}

	/**
	 * Compiles the project into an executable phar file.
	 *
	 * @param string $pharFile
	 *   (optional) The path (absolute or relative to the CWD) to write the
	 *   resulting phar file to.
	 *
	 * @return void
	 */
	public function compile($pharFile = 'twigc.phar') {
		$this->output->writeln('Compiling phar...');

		$this->output->writeln('', ConsoleOutput::VERBOSITY_VERBOSE);
		$this->extractVersionInformation();

		if ( file_exists($pharFile) ) {
			unlink($pharFile);
		}

		$phar = new \Phar($pharFile, 0, 'twigc.phar');
		$phar->setSignatureAlgorithm(\Phar::SHA1);
		$phar->startBuffering();

		$this->output->writeln('', ConsoleOutput::VERBOSITY_VERBOSE);
		$this->output->writeln('Adding src files...');
		$this->addSrc($phar);

		$this->output->writeln('', ConsoleOutput::VERBOSITY_VERBOSE);
		$this->output->writeln('Adding vendor files...');
		$this->addVendor($phar);

		$this->output->writeln('', ConsoleOutput::VERBOSITY_VERBOSE);
		$this->output->writeln('Adding root files...');
		$this->addRoot($phar);

		$this->output->writeln('', ConsoleOutput::VERBOSITY_VERBOSE);
		$this->output->writeln('Adding bin files...');
		$this->addBin($phar);

		$phar->setStub($this->getStub());
		$phar->stopBuffering();

		unset($phar);

		chmod($pharFile, 0755);

		$this->output->writeln('', ConsoleOutput::VERBOSITY_VERBOSE);
		$this->output->writeln("Compiled to ${pharFile}.");

		/*
			// Re-sign the phar with reproducible time stamps and signature
			$util = new Timestamps($pharFile);
			$util->updateTimestamps($this->versionDate);
			$util->save($pharFile, \Phar::SHA1);
		*/
	}

	/**
	 * Extracts the application version information from the git repository and
	 * sets the associated object properties.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException if `git describe` fails
	 * @throws \RuntimeException if `git log` fails
	 * @throws \RuntimeException if `git log` fails (2)
	 */
	protected function extractVersionInformation() {
		$workDir = escapeshellarg(__DIR__);

		// Get version number
		$output = [];
		exec("cd ${workDir} && git describe --tags --match='v*.*.*' --dirty='!'", $output, $ret);

		if ( $ret !== 0 || empty($output) ) {
			$output = ['0.0.0'];
		}

		$tokens = explode('-', trim($output[0]));

		$this->versionNumber = rtrim(ltrim($tokens[0], 'v'), '!');

		// If we're ahead of a tag, add the number of commits
		if ( count($tokens) > 1 ) {
			$this->versionNumber .= '-plus' . rtrim($tokens[1], '!');
		}

		// If the index is dirty, add that
		if ( rtrim(implode('-', $tokens), '!') !== implode('-', $tokens) ) {
			$this->versionNumber .= '-dirty';
		}

		// Get version last commit hash
		$output = [];
		exec("cd ${workDir} && git log -1 --pretty='%H' HEAD", $output, $ret);

		if ( $ret !== 0 || empty($output) ) {
			throw new \RuntimeException(
				'An error occurred whilst running `git log`'
			);
		}

		$this->versionCommit = trim($output[0]);

		// Get version last commit date
		$output = [];
		exec("cd ${workDir} && git log -1 --pretty='%ci' HEAD", $output, $ret);

		if ( $ret !== 0 || empty($output) ) {
			throw new \RuntimeException(
				'An error occurred whilst running `git log`'
			);
		}

		$this->versionDate = new \DateTime(trim($output[0]));
		$this->versionDate->setTimezone(new \DateTimeZone('UTC'));

		if ( $this->output->isVerbose() ) {
			$this->output->writeln(
				'Got version number: ' . $this->versionNumber
			);
			$this->output->writeln(
				'Got version commit: ' . $this->versionCommit
			);
			$this->output->writeln(
				'Got version date:   ' . $this->versionDate->getTimestamp()
			);
		}
	}

	/**
	 * Adds a file to a phar.
	 *
	 * @param \Phar $phar
	 *   The phar file to add to.
	 *
	 * @param \SplFileInfo|string $file
	 *   The file to add, or its path.
	 *
	 * @param null|bool $strip
	 *   (optional) Whether to strip extraneous white space from the file in
	 *   order to reduce its size. The default is to auto-detect based on file
	 *   extension.
	 *
	 * @return void
	 */
	protected function addFile($phar, $file, $strip = null) {
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

		$this->output->writeln("Adding file: ${path}", ConsoleOutput::VERBOSITY_VERBOSE);

		$content = file_get_contents($file);

		// Strip interpreter directives
		if ( strpos($path, 'bin/') === 0 ) {
			$content = preg_replace('%^#!/usr/bin/env php\s*%', '', $content);

		// Replace version place-holders
		} elseif ( $path === 'src/Twigc/Twigc.php' ) {
			$content = str_replace(
				[
					'%version_number%',
					'%version_commit%',
					'%version_date%',
				],
				[
					$this->versionNumber,
					$this->versionCommit,
					$this->versionDate->format('Y-m-d H:i:s'),
				],
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
	 * Removes extraneous white space from a string whilst preserving PHP line
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
	protected function stripWhiteSpace($source, $type = 'php') {
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
			} elseif (T_WHITESPACE === $token[0]) {
				// Collapse consecutive spaces
				$space = preg_replace('#[ \t]+#', ' ', $token[1]);
				// Normalise new-lines to \n
				$space = preg_replace('#(?:\r\n|\r|\n)#', "\n", $space);
				// Trim leading spaces
				$space = preg_replace('#\n[ ]+#', "\n", $space);
				$output .= $space;
			// Anything else, return as-is
			} else {
				$output .= $token[1];
			}
		}

		return $output;
	}

	/**
	 * Adds files from src directory to a phar.
	 *
	 * @param \Phar $phar The phar file to add to.
	 *
	 * @return void
	 */
	protected function addSrc($phar) {
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
		$devPaths = \Dana\Twigc\Twigc::getComposerDevPackages();
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
	protected function addRoot($phar) {
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
	protected function addBin($phar) {
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
			 * @author  dana geier <dana@dana.is>
			 * @license MIT
			 */

			\Phar::mapPhar('twigc.phar');
			require 'phar://twigc.phar/bin/twigc';
			__HALT_COMPILER();
		";

		return str_replace("\t", '', trim($stub)) . "\n";
	}
}

