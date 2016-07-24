<?php

/**
 * This file is part of twigc.
 *
 * @author  dana geier <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Default twigc command.
 */
class DefaultCommand extends Command {
	/**
	 * {@inheritdoc}
	 */
	protected function configure() {
		$this
			->setName('twigc')
			->setDescription('Compile a Twig template')
			->addArgument(
				'template',
				InputArgument::OPTIONAL,
				'Twig template file to render (use `-` for STDIN)'
			)
			->addOption(
				'help',
				'h',
				InputOption::VALUE_NONE,
				'Display this usage help'
			)
			->addOption(
				'version',
				'V',
				InputOption::VALUE_NONE,
				'Display version information'
			)
			->addOption(
				'credits',
				null,
				InputOption::VALUE_NONE,
				'Display dependency credits (including Twig version)'
			)
			->addOption(
				'cache',
				null,
				InputOption::VALUE_REQUIRED,
				'Enable caching to specified directory'
			)
			->addOption(
				'dir',
				'd',
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Add search directory to loader'
			)
			->addOption(
				'escape',
				'e',
				InputOption::VALUE_REQUIRED,
				'Set autoescape environment option'
			)
			->addOption(
				'json',
				'j',
				InputOption::VALUE_REQUIRED,
				'Pass variables as JSON (dictionary string or file path)'
			)
			->addOption(
				'pair',
				'p',
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Pass variable as key=value pair'
			)
			->addOption(
				'query',
				null,
				InputOption::VALUE_REQUIRED,
				'Pass variables as URL query string'
			)
			->addOption(
				'strict',
				's',
				InputOption::VALUE_NONE,
				'Enable strict_variables environment option'
			)
		;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		switch ( true ) {
			// Display usage help
			case $input->getOption('help'):
				return $this->doHelp($input, $output);
			// Display version information
			case $input->getOption('version'):
				return $this->doVersion($input, $output);
			// Display package credits
			case $input->getOption('credits'):
				return $this->doCredits($input, $output);
		}
		// Render Twig template
		return $this->doRender($input, $output);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Overriding this prevents TextDescriptor from displaying the Help section.
	 */
	public function getProcessedHelp() {
		return '';
	}

	/**
	 * Displays usage help.
	 *
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	public function doHelp(InputInterface $input, OutputInterface $output) {
		(new DescriptorHelper())->describe($output, $this);
		return 0;
	}

	/**
	 * Displays version information.
	 *
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	public function doVersion(InputInterface $input, OutputInterface $output) {
		$nameFmt    = '<info>twigc</info>';
		$versionFmt = '<comment>%s</comment> (<comment>%s</comment> @ <comment>%s</comment>)';

		$output->writeln(sprintf(
			"${nameFmt} version ${versionFmt}",
			\Dana\Twigc\Twigc::VERSION_NUMBER,
			\Dana\Twigc\Twigc::VERSION_COMMIT,
			\Dana\Twigc\Twigc::VERSION_DATE
		));

		return 0;
	}

	/**
	 * Displays package credits.
	 *
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	public function doCredits(InputInterface $input, OutputInterface $output) {
		$installed = \Dana\Twigc\Twigc::getComposerPackages();

		$table = new Table($output);
		$table->setStyle('compact');
		$table->getStyle()->setVerticalBorderChar('');
		$table->getStyle()->setCellRowContentFormat('%s  ');
		$table->setHeaders(['name', 'version', 'licence']);

		foreach ( $installed as $package ) {
			$table->addRow([
				$package->name,
				ltrim($package->version, 'v'),
				implode(', ', $package->license) ?: '?',
			]);
		}
		$table->render();
	}

	/**
	 * Renders a Twig template.
	 *
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	public function doRender(InputInterface $input, OutputInterface $output) {
		$inputData = [];
		$template  = $input->getArgument('template');
		$template  = $template === null ? '-' : $template;
		$cache     = $input->getOption('cache');
		$cache     = $cache === null ? false : $cache;
		$dirs      = $template === '-'  ? []  : [dirname($template)];
		$dirs      = array_merge($dirs, $input->getOption('dir'));
		$temp      = false;
		$strict    = (bool) $input->getOption('strict');
		$escape    = $input->getOption('escape');
		$inputs    = [
			'json'  => (int) ($input->getOption('json') !== null),
			'pair'  => (int) (! empty($input->getOption('pair'))),
			'query' => (int) ($input->getOption('query') !== null),
		];

		// If we're reading from STDIN, but STDIN is a TTY, print help and die
		if ( $template === '-' && posix_isatty(\STDIN) ) {
			$this->doHelp($input, $output);
			return 1;
		}

		// Validate search directories
		foreach ( $dirs as $dir ) {
			if ( ! is_dir($dir) ) {
				throw new \InvalidArgumentException(
					"Illegal search directory: ${dir}"
				);
			}
		}

		// Normalise auto-escape setting
		if ( $escape === null ) {
			$escape = true;
		} else {
			$bool = filter_var($escape, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);

			if ( $bool !== null ) {
				$escape = $bool;
			} else {
				$escape = strtolower($escape);
			}
		}

		// Because Console doesn't allow us to see the order of options supplied
		// at the command line, there's no good way to sort out the precedence
		// amongst the different input methods... so let's just say we can only
		// use one of them at a time
		if ( array_sum($inputs) > 1 ) {
			throw new \InvalidArgumentException(
				'-j, -p, and --query options are mutually exclusive'
			);
		}

		// Input data supplied via query string
		if ( ($query = $input->getOption('query')) !== null ) {
			if ( $query && $query[0] === '?' ) {
				$query = substr($query, 1);
			}
			parse_str($query, $inputData);

		// Input data supplied via JSON
		} elseif ( ($json = $input->getOption('json')) !== null ) {
			$json = trim($json);

			// JSON supplied via STDIN
			if ( $json === '-' ) {
				if ( $template === '-' ) {
					throw new \InvalidArgumentException(
						'Can not read both template and JSON input from STDIN'
					);
				}
				if ( posix_isatty(\STDIN) ) {
					throw new \InvalidArgumentException(
						'Expected JSON input on STDIN'
					);
				}
				$json = file_get_contents('php://stdin');

			// JSON supplied via file
			} elseif ( $json && $json[0] !== '{' ) {
				if ( ! file_exists($json) || is_dir($json) ) {
					throw new \InvalidArgumentException(
						"Missing or illegal JSON file name: ${json}"
					);
				}
				$json = file_get_contents($json);
			}

			// This check is here to prevent errors if the input is just empty
			if ( trim($json) !== '' ) {
				$inputData = json_decode($json, true);
			}

			if ( ! is_array($inputData) ) {
				throw new \InvalidArgumentException(
					'JSON input must be a dictionary'
				);
			}

		// Input data supplied via key=value pair
		} elseif ( count($input->getOption('pair')) ) {
			foreach ( $input->getOption('pair') as $pair ) {
				$kv = explode('=', $pair, 2);

				if ( count($kv) !== 2 ) {
					throw new \InvalidArgumentException(
						"Illegal key=value pair: ${pair}"
					);
				}

				$inputData[$kv[0]] = $kv[1];
			}
		}

		// Validate key names now
		foreach ( $inputData as $key => $value ) {
			if ( ! preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#', $key) ) {
				throw new \InvalidArgumentException(
					"Illegal variable name: ${key}"
				);
			}
		}

		// Template supplied via STDIN
		if ( $template === '-' ) {
			// If we've been supplied one or more search directories, we'll need
			// to write the template out to a temp directory so we can use the
			// file-system loader
			if ( $dirs ) {
				$temp     = true;
				$template = implode('/', [
					sys_get_temp_dir(),
					implode('.', ['twigc', getmypid(), md5(time())]),
					'-',
				]);

				mkdir(dirname($template));
				file_put_contents($template, file_get_contents('php://stdin'), LOCK_EX);

				$dirs = array_merge([dirname($template)], $dirs);

				$loader = new \Twig_Loader_Filesystem($dirs);

			// Otherwise, we can just use the array loader, which is a little
			// faster and cleaner
			} else {
				$loader = new \Twig_Loader_Array([
					$template => file_get_contents('php://stdin'),
				]);
			}

		// Template supplied via file path
		} else {
			$loader = new \Twig_Loader_Filesystem($dirs);
		}

		try {
			$twig = new \Twig_Environment($loader, [
				'cache'            => $cache,
				'debug'            => false,
				'strict_variables' => $strict,
				'autoescape'       => $escape,
			]);

			$output->writeln(
				rtrim($twig->render(basename($template), $inputData), "\r\n")
			);
		} finally {
			if ( $temp ) {
				unlink($template);
				rmdir(dirname($template));
			}
		}

		return 0;
	}
}

