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
    $delimiter = ",";
    $skip_empty_lines = TRUE;
    $trim_fields = TRUE;
    $use_header = $this->configuration['header'];
    $enc = preg_replace('/(?<!")""/', '!!Q!!', $data);
    $enc = preg_replace_callback(
        '/"(.*?)"/s',
        function ($field) {
            return urlencode(utf8_encode($field[1]));
        },
        $enc
    );
    $lines = preg_split($skip_empty_lines ? ($trim_fields ? '/( *\R)+/s' : '/\R+/s') : '/\R/s', $enc);
    $data = array_map(
      function ($line) use ($delimiter, $trim_fields) {
        $fields = $trim_fields ? array_map('trim', explode($delimiter, $line)) : explode($delimiter, $line);
        return array_map(
          function ($field) {
            return html_entity_decode(htmlentities(str_replace('!!Q!!', '"', utf8_decode(urldecode($field)))));
          },
          $fields
        );
      },
      $lines
    );
    if ($use_header) {
      $header = array_shift($data);
      foreach ($header as &$value) {
        $value = strtolower(preg_replace([
          '/[^a-zA-Z0-9]+/',
          '/-+/',
          '/^-+/',
          '/-+$/',
        ], ['_', '_', '', ''], $value));
      }
      foreach ($data as &$fields) {
        $fields = array_combine($header, $fields);
      }
    }
    return $data;
  }

}
