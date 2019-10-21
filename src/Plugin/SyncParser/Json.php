<?php

namespace Drupal\sync\Plugin\SyncParser;

use Drupal\sync\Plugin\SyncParserBase;
use function GuzzleHttp\json_decode;

/**
 * Plugin implementation of the 'json' sync parser.
 *
 * @SyncParser(
 *   id = "json",
 *   label = @Translation("JSON"),
 * )
 */
class Json extends SyncParserBase {

  /**
   * {@inheritdoc}
   */
  protected function parse($data) {
    return json_decode($data, TRUE);
  }

}
