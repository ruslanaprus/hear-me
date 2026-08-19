<?php

namespace Drupal\hear_me\Service;

/**
 * Reports content bundles created or skipped during audio field provisioning.
 *
 * @internal
 */
final class AudioFieldProvisionResult {

  /**
   * Constructs an audio field provisioning result.
   *
   * @param string[] $createdBundles
   *   Content bundle IDs where the field was created.
   * @param string[] $skippedBundles
   *   Content bundle IDs skipped because the field or bundle was unavailable.
   */
  public function __construct(
    public readonly array $createdBundles,
    public readonly array $skippedBundles,
  ) {}

}
