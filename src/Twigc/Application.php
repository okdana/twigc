<?php

/**
 * This file is part of twigc.
 *
 * @author  dana geier <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * twigc application container.
 *
 * This class overrides a bunch of the default Console behaviour to make the
 * application work like a more traditional UNIX CLI tool.
 */
class Application extends BaseApplication {
	/**
	 * {@inheritdoc}
	 */
	public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN') {
		parent::__construct('twigc', \Dana\Twigc\Twigc::VERSION_NUMBER);
	}

	/**
	 * {@inheritdoc}
	 *
	 * In a normal Console application, this method handles the --version and
	 * --help options. In our application, the default command handles all of
	 * that.
	 */
	public function doRun(InputInterface $input, OutputInterface $output) {
		$name = $this->getCommandName($input);

		if ( ! $name ) {
			$name  = $this->defaultCommand;
			$input = new ArrayInput(['command' => $this->defaultCommand]);
		}

		$command = $this->find($name);

		$this->runningCommand = $command;
		$exitCode             = $this->doRunCommand($command, $input, $output);
		$this->runningCommand = null;

		return $exitCode;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDefinition() {
		$definition = parent::getDefinition();
		$definition->setArguments();
		return $definition;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Since we're a one-command application, we always use the name of the
	 * default command.
	 */
	protected function getCommandName(InputInterface $input) {
		return $this->getDefaultCommands()[0]->getName();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Since we're a one-command application, we always use the definition of
	 * the default command. This means that none of the built-in Console options
	 * like --help and --ansi are automatically defined — the default command
	 * must handle all of that.
	 */
	protected function getDefaultInputDefinition() {
		return $this->getDefaultCommands()[0]->getDefinition();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Since we're a one-command application, we always return just the default
	 * command.
	 */
	protected function getDefaultCommands() {
		return [new \Dana\Twigc\DefaultCommand()];
	}

}

