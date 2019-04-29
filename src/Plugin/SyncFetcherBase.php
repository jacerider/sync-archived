<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for Sync Fetcher plugins.
 */
abstract class SyncFetcherBase extends PluginBase implements SyncFetcherInterface {

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
    $configuration += [
      'url' => '',
      'query' => [],
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Build the request.
   */
  abstract public function fetch();

}
