<?php

namespace Drupal\sync;

/**
 * Interface SyncStorageInterface.
 */
interface SyncStorageInterface {

  /**
   * Load an entity by sync id and entity type.
   */
  public function loadEntity($id, $entity_type);

}
