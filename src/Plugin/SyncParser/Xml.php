<?php

namespace Drupal\sync\Plugin\SyncParser;

use Drupal\sync\Plugin\SyncParserBase;

/**
 * Plugin implementation of the 'xml' sync parser.
 *
 * @SyncParser(
 *   id = "xml",
 *   label = @Translation("XML"),
 * )
 */
class Xml extends SyncParserBase {

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
      'base_key' => '',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function parse($data) {
    if (empty($data)) {
      return [];
    }
    $xml = simplexml_load_string($data);
    $base_key = $this->configuration['base_key'];
    $data = $this->recurseXml($xml);
    return !empty($base_key) && isset($data[$base_key]) ? $data[$base_key] : $data;
  }

  /**
   * Recursively crawl through XML and return results as an array.
   *
   * @param \SimpleXmlElement $xml
   *   The XML element to crawl through.
   * @param array $arr
   *   The results.
   *
   * @return array
   *   The XML results as an array.
   */
  private function recurseXml(\SimpleXMLElement $xml, array $arr = []) {
    $iter = 0;
    if ($xml) {
      foreach ($xml->children() as $b) {
        $a = $b->getName();
        if (!$b->children()) {
          $arr[$a] = trim($b[0]);
        }
        else {
          $arr[$a][$iter] = [];
          $arr[$a][$iter] = $this->recurseXml($b, $arr[$a][$iter]);
        }
        $iter++;
      }
    }
    return $arr;
  }

}
