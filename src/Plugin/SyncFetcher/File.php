<?php

namespace Drupal\sync\Plugin\SyncFetcher;

use Drupal\sync\Plugin\SyncFetcherBase;

/**
 * Plugin implementation of the 'file' sync resource.
 *
 * @SyncFetcher(
 *   id = "file",
 *   label = @Translation("File"),
 * )
 */
class File extends SyncFetcherBase {

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
      'path' => '',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function fetch() {
    $data = [];
    $filepath = \Drupal::root() . '/' . $this->configuration['path'];
    if (!file_exists($filepath)) {
      $message = t('The file %filepath could not be found.', [
        '%filepath' => $filepath,
      ]);
      drupal_set_message($message, 'error');
      throw new \Exception($message);
    }
    return file_get_contents($filepath);
  }

}
