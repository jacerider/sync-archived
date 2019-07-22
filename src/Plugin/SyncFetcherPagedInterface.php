<?php

namespace Drupal\sync\Plugin;

/**
 * Defines an interface for Sync Resource plugins.
 */
interface SyncFetcherPagedInterface extends SyncFetcherInterface {

  /**
   * Called when paging is enabled.
   *
   * @return array|null
   *   Should return results of paged fetch. If null or empty array, paging will
   *   end.
   */
  public function fetchPage($previous_data, $page);

}
