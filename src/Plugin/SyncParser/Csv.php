<?php

namespace Drupal\sync\Plugin\SyncParser;

use Drupal\sync\Plugin\SyncParserBase;

/**
 * Plugin implementation of the 'csv' sync parser.
 *
 * @SyncParser(
 *   id = "csv",
 *   label = @Translation("CSV"),
 * )
 */
class Csv extends SyncParserBase {

  /**
   * {@inheritdoc}
   */
  protected function defaultSettings() {
    return [
      'header' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  protected function parse($data) {
    $use_header = $this->configuration['header'];
    $rows = array_filter(explode(PHP_EOL, $data));
    $csv = array_map('str_getcsv', $rows);
    if ($use_header) {
      array_walk($csv, function (&$a) use ($csv) {
        if (count($csv[0]) === count($a)) {
          $a = array_combine($csv[0], $a);
        }
      });
      array_shift($csv);
    }
    return $csv;
  }

}
