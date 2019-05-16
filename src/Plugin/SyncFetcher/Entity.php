<?php

namespace Drupal\sync\Plugin\SyncFetcher;

use Drupal\sync\Plugin\SyncFetcherBase;

/**
 * Plugin implementation of the 'entity' sync resource.
 *
 * @SyncFetcher(
 *   id = "entity",
 *   label = @Translation("Entity"),
 * )
 */
class Entity extends SyncFetcherBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $configuration += [
      'entity_type' => '',
      'properties' => [],
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function fetch() {
    $data = [];
    foreach (\Drupal::entityTypeManager()->getStorage($this->configuration['entity_type'])->loadByProperties($this->configuration['properties']) as $entity) {
      $data[]['entity'] = $entity;
    }
    return $data;
  }

}
