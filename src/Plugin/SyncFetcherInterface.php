<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Sync Fetcher plugins.
 */
interface SyncFetcherInterface extends PluginInspectionInterface {

  /**
   * Prepare for fetching.
   *
   * @param int $page_number
   *   The request page number.
   * @param \Drupal\sync\Plugin\SyncDataItems $previous_data
   *   The request page number.
   *
   * @return mixed
   *   The results of the fetch.
   */
  public function doFetch($page_number = 0, SyncDataItems $previous_data = NULL);

  /**
   * Should fetcher act on another page.
   *
   * @param int $page_number
   *   The request page number.
   * @param \Drupal\sync\Plugin\SyncDataItems $previous_data
   *   The request page number.
   *
   * @return bool
   *   If TRUE, sync will continue until this returns false.
   */
  public function hasNextPage($page_number = 1, SyncDataItems $previous_data = NULL);

}
