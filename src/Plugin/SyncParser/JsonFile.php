<?php

namespace Drupal\sync\Plugin\SyncParser;

/**
 * Plugin implementation of the 'file' sync parser.
 *
 * @SyncParser(
 *   id = "json_file",
 *   label = @Translation("JSON File"),
 * )
 */
class JsonFile extends File {

  /**
   * {@inheritdoc}
   */
  public function parse($data) {
    $data = json_decode($data, TRUE);
    return parent::parse($data);
  }

}
