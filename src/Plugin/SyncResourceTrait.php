<?php

namespace Drupal\sync\Plugin;

/**
 * Wrapper methods for loading resources.
 */
trait SyncResourceTrait {

  /**
   * The sync resource manager.
   *
   * @var \Drupal\sync\Plugin\SyncResourceManager
   */
  protected $syncResourceManager;

  /**
   * Load resource.
   *
   * @return array
   *   An array of data.
   */
  protected function getResource($resource_id) {
    $manager = $this->syncResourceManager();
    if ($manager->hasDefinition($resource_id)) {
      return $manager->createInstance($resource_id);
    }
    return NULL;
  }

  /**
   * Retrieves the sync resoure manager.
   *
   * @return \Drupal\sync\Plugin\SyncResourceManager
   *   The sync resource manager.
   */
  protected function syncResourceManager() {
    if (!isset($this->syncResourceManager)) {
      $this->syncResourceManager = \Drupal::service('plugin.manager.sync_resource');
    }
    return $this->syncResourceManager;
  }

}
