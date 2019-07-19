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
   * {@inheritdoc}
   */
  protected function defaultSettings() {
    return [
      'path' => '',
    ];
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
