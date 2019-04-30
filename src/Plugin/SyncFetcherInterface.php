<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Sync Fetcher plugins.
 */
interface SyncFetcherInterface extends PluginInspectionInterface {

  /**
   * Build the request.
   */
  public function fetch();

}
