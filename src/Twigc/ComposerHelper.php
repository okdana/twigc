<?php

/**
 * This file is part of twigc.
 *
 * @author  dana <dana@dana.is>
 * @license MIT
 */

namespace Dana\Twigc;

/**
 * Helper class with various functions for interacting with Composer's lock
 * file.
 */
class ComposerHelper {
  /**
   * Get an array of the installed non-dev packages listed in a Composer lock
   * file.
   *
   * @param string|null (optional) The path to the lock file to parse.
   *
   * @return object[]
   */
  public function getPackages(string $lockFile = null) {
    $packages = $this->parseLockFile($lockFile)['packages'];
    return $this->massagePackages($packages);
  }

  /**
   * Get an array of the installed dev packages listed in a Composer lock file.
   *
   * @param string|null (optional) The path to the lock file to parse.
   *
   * @return object[]
   */
  public function getDevPackages(string $lockFile = null) {
    $packages = $this->parseLockFile($lockFile)['packages-dev'];
    return $this->massagePackages($packages);
  }

  /**
   * Get an array of data representing a Composer lock file.
   *
   * @param string $path
   *   (optional) The path to the lock file to parse. The default is the lock
   *   file associated with the current project.
   *
   * @return array
   *
   * @throws \RuntimeException if composer.lock doesn't exist
   * @throws \RuntimeException if composer.lock can't be decoded
   */
  public function parseLockFile(string $lockFile = null): array {
    $lockFile = $lockFile ?? __DIR__ . '/../../composer.lock';

    if ( ! file_exists($lockFile) ) {
      throw new \RuntimeException('Missing ' . basename($lockFile));
    }

    $lock = json_decode(file_get_contents($lockFile), true);

    if ( empty($lock) || ! isset($lock['packages']) ) {
      throw new \RuntimeException('Error decoding ' . basename($lockFile));
    }

    return $lock;
  }

  /**
   * Sort and object-ify an array of package data.
   *
   * @param array $packages Package data from composer.lock.
   *
   * @return object[]
   */
  public function massagePackages(array $packages): array {
    usort($packages, function ($a, $b) {
      return strcasecmp($a['name'], $b['name']);
    });

    foreach ( $packages as &$package ) {
      $package = (object) $package;
    }

    return $packages;
  }
}
