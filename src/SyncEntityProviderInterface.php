<?php

namespace Drupal\sync;

/**
 * Interface SyncEntityProviderInterface.
 */
interface SyncEntityProviderInterface {

  /**
   * Get an existing entity.
   *
   * @param string $id
   *   The unique sync id.
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param array $values
   *   The values used when loading an entity that could not be found via ID.
   * @param string $group
   *   The group id.
   */
  public function get($id, $entity_type, $bundle = NULL, array $values = [], $group = 'default');

  /**
   * Get or create an unstaved entity.
   *
   * If entity was created, it is not saved.
   *
   * @param string $id
   *   The unique sync id. This may or may not be the entity id. It is the ID
   *   the entity provider will use to retrieve this entity in the future.
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param array $values
   *   The values used as the initial entity values when creating and as the
   *   properties to use when loading an entity that could not be found via ID.
   * @param string $group
   *   Used to segment sync data that uses the same ID. Records a seperate
   *   changed timestamp for each id => group so that sync providers can
   *   manage their own data without overlap. Typically this is handled
   *   automatically and can be ignored.
   */
  public function getOrNew($id, $entity_type, $bundle, array $values = [], $group = 'default');

  /**
   * Get or create and save a entity.
   *
   * If entity was created, it is saved.
   *
   * @param string $id
   *   The unique sync id. This may or may not be the entity id. It is the ID
   *   the entity provider will use to retrieve this entity in the future.
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param array $values
   *   The values used as the initial entity values when creating and as the
   *   properties to use when loading an entity that could not be found via ID.
   * @param string $group
   *   Used to segment sync data that uses the same ID. Records a seperate
   *   changed timestamp for each id => group so that sync providers can
   *   manage their own data without overlap. Typically this is handled
   *   automatically and can be ignored.
   */
  public function getOrCreate($id, $entity_type, $bundle, array $values = [], $group = 'default');

}
