<?php

namespace Drupal\sync;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Class SyncEntityProvider.
 */
class SyncEntityProvider implements SyncEntityProviderInterface {

  /**
   * The sync storage.
   *
   * @var \Drupal\sync\SyncStorageInterface
   */
  protected $syncStorage;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * An array of local entities keyed by entity_type > bundle > Sf ID.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   *   An array of stored entities.
   */
  protected $entities;

  /**
   * Constructs a new MapeLocalManager object.
   */
  public function __construct(SyncStorageInterface $sync_storage, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user) {
    $this->syncStorage = $sync_storage;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public function get($id, $entity_type, $bundle, array $values = [], $group = 'default') {
    $entity = $this->syncStorage->loadEntity($id, $entity_type);
    if (!$entity && !empty($values)) {
      // We do not have a record of this entity within sync. We check to see if
      // one already exists.
      $bundle_key = $this->entityTypeManager->getDefinition($entity_type)->getKey('bundle');
      if ($bundle_key) {
        $values[$bundle_key] = $bundle;
      }
      $results = $this->entityTypeManager->getStorage($entity_type)->loadByProperties($values);
      if (!empty($results)) {
        $entity = reset($results);
        // Create sync record so it can be retrieved faster next time.
        $this->syncStorage->save($id, $entity, FALSE, $group);
      }
    }
    if ($entity) {
      if ($entity instanceof EntityChangedInterface) {
        $entity->setChangedTime(\Drupal::time()->getRequestTime());
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getNew($entity_type, $bundle, array $values = []) {
    $bundle_key = $this->entityTypeManager->getDefinition($entity_type)->getKey('bundle');
    if ($bundle_key) {
      $values[$bundle_key] = $bundle;
    }
    $uid = $this->currentUser->id();
    if (empty($uid)) {
      $uid = 1;
    }
    return $this->entityTypeManager->getStorage($entity_type)->create([
      'uid' => $uid,
    ] + $values);
  }

  /**
   * {@inheritdoc}
   */
  public function getOrNew($id, $entity_type, $bundle, array $values = [], $group = 'default') {
    $entity = $this->get($id, $entity_type, $bundle, $values, $group);
    if (empty($entity)) {
      $entity = $this->getNew($entity_type, $bundle, $values);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrCreate($id, $entity_type, $bundle, array $values = [], $group = 'default') {
    $entity = $this->get($id, $entity_type, $bundle, $values, $group);
    if (empty($entity)) {
      $entity = $this->getNew($entity_type, $bundle, $values);
      $this->save($id, $entity, $group);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save($id, $entity, $group = 'default') {
    $status = $entity->save();
    if ($status) {
      $this->syncStorage->save($id, $entity, FALSE, $group);
    }
    return $status;
  }

}
