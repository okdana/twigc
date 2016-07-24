<?php

/**
 * This file is part of twigc.
 *
 * @author  dana geier <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

/**
 * Holds various project-specific constants and methods.
 */
class Twigc {
	const BASE_DIR       = __DIR__ . '/../..';
	const VERSION_NUMBER = '%version_number%';
	const VERSION_COMMIT = '%version_commit%';
	const VERSION_DATE   = '%version_date%';

	/**
	 * Returns an array of data representing the project's Composer lock file.
	 *
	 * @return array
	 *
	 * @throws \RuntimeException if composer.lock doesn't exist
	 * @throws \RuntimeException if composer.lock can't be decoded
	 */
	private static function parseComposerLock() {
		$lockFile = static::BASE_DIR . '/composer.lock';

		if ( ! file_exists($lockFile) ) {
			throw new \RuntimeException('Missing ' . basename($lockFile));
		}

		$installed = json_decode(file_get_contents($lockFile), true);

		if ( empty($installed) || ! isset($installed['packages']) ) {
			throw new \RuntimeException('Error decoding ' . basename($lockFile));
		}

		return $installed;
	}

	/**
	 * Sorts and object-ifies an array of package data.
	 *
	 * @param array $packages Package data from composer.lock.
	 *
	 * @return array
	 */
	private static function massagePackages(array $packages) {
		usort($packages, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		foreach ( $packages as &$package ) {
			$package = (object) $package;
		}

		return $packages;
	}

	/**
	 * Returns an array of installed non-dev Composer packages based on the
	 * project's Composer lock file.
	 *
	 * @return object[] An array of objects representing Composer packages.
	 */
	public static function getComposerPackages() {
		$packages = static::parseComposerLock()['packages'];

		return static::massagePackages($packages);
	}

	/**
	 * Returns an array of installed dev Composer packages based on the
	 * project's lock file.
	 *
	 * @return object[] An array of objects representing Composer packages.
	 */
	public static function getComposerDevPackages() {
		$packages = static::parseComposerLock()['packages-dev'];

		return static::massagePackages($packages);
	}
}

