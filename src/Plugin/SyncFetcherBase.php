<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for Sync Fetcher plugins.
 */
abstract class SyncFetcherBase extends PluginBase implements SyncFetcherInterface {

  /**
   * Determines if paged featching is supported.
   *
   * @var bool
   */
  protected $supportsPaging = FALSE;

  /**
   * Constructs a SyncFetcher object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $configuration += $this->defaultSettings();
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Provides default settings.
   */
  protected function defaultSettings() {
    return [];
  }

  /**
   * Check if paged fetching is supported.
   */
  public function supportsPaging() {
    return $this->supportsPaging;
  }

  /**
   * Called when paging is enabled.
   *
   * @return array
   *   Should return results of paged fetch. If empty array, paging will end.
   */
  public function fetchPage($previous_data, $page) {
    return [];
  }

  /**
   * Build the request.
   */
  abstract public function fetch();

}
