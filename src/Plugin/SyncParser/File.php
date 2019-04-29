<?php

namespace Drupal\sync\Plugin\SyncParser;

use Drupal\sync\Plugin\SyncParserBase;
use GuzzleHttp\Psr7\Stream;

/**
 * Plugin implementation of the 'file' sync parser.
 *
 * @SyncParser(
 *   id = "file",
 *   label = @Translation("File"),
 * )
 */
class File extends SyncParserBase {

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
      'destination' => 'public://import',
      'filename' => 'file.txt',
      'replace' => FALSE,
      'filename_property' => NULL,
      'data_property' => NULL,
      'filename_prefix' => '',
      'base64_decode' => TRUE,
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function parse($data) {
    $results = [];
    if (!is_array($data)) {
      $data = [$data];
    }
    $destination = $this->configuration['destination'];
    file_prepare_directory($destination, FILE_CREATE_DIRECTORY);
    foreach ($data as $value) {
      $replace = $this->configuration['replace'];
      $filename_property = $this->configuration['filename_property'];
      $data_property = $this->configuration['data_property'];
      $filename = !empty($filename_property) && !empty($value[$filename_property]) ? $value[$filename_property] : $this->configuration['filename'];
      $filename = $this->configuration['filename_prefix'] . $filename;
      $value = !empty($data_property) && is_array($value) && !empty($value[$data_property]) ? $value[$data_property] : $value;
      if ($this->configuration['base64_decode']) {
        $value = base64_decode($value);
      }
      $results[] = file_save_data($value, $destination . '/' . $filename, $replace);
    }
    return $results;
  }

}
