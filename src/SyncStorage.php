<?php

namespace Drupal\sync;

use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class SyncStorage.
 */
class SyncStorage implements SyncStorageInterface {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;
  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new SyncStorage object.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query.
   */
  public function getQuery() {
    return $this->database->select('sync')->fields('sync');
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query.
   */
  public function getDataQuery($group = 'default') {
    $query = $this->database->select('sync_data');
    $query->join('sync', 'sync', 'sync.id = sync_data.id');
    $query->fields('sync_data');
    $query->fields('sync');
    if ($group) {
      $query->condition('sync_data.group', $group);
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array $values = []) {
    $query = $this->getQuery();
    $this->buildPropertyQuery($query, $values);
    return $query->execute()->fetchAllAssoc('id');
  }

  /**
   * Builds a query.
   */
  protected function buildPropertyQuery(ConditionInterface $query, array $values) {
    foreach ($values as $name => $value) {
      // Cast scalars to array so we can consistently use an IN condition.
      $query->condition($name, (array) $value, 'IN');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadEntity($id, $entity_type) {
    $entity = NULL;
    $data = $this->loadByProperties([
      'id' => $id,
      'entity_type' => $entity_type,
    ]);
    if (isset($data[$id])) {
      $entity = $this->entityTypeManager->getStorage($data[$id]->entity_type)->load($data[$id]->entity_id);
      // We temporarily store the locked state on the entity.
      $entity->syncIsLocked = !empty($data[$id]->locked);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save($id, EntityInterface $entity, $locked = FALSE, $group = 'default') {
    $status = $this->database->merge('sync')
      ->key(['id' => $id, 'entity_type' => $entity->getEntityTypeId()])
      ->fields([
        'entity_id' => $entity->id(),
        'locked' => $locked === TRUE ? 1 : 0,
      ])
      ->execute();
    if ($status) {
      $status = $this->database->merge('sync_data')
        ->key(['id' => $id, 'group' => $group])
        ->fields([
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }
    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function lastUpdated($id, $group = 'default') {
    $query = $this->database->select('sync_data');
    $query->fields('sync_data', ['changed']);
    $query->condition('id', $id);
    $query->condition('group', $group);
    $query->range(0, 1);
    return $query->execute()->fetchField(0);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByProperties(array $values = []) {
    $data = $this->loadByProperties($values);
    foreach ($data as $id => $item) {
      $query = $this->database->delete('sync');
      $this->buildPropertyQuery($query, $values);
      $status = $query->execute();
      if ($status) {
        $status = $this->database->delete('sync_data')
          ->condition('id', $id)
          ->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function wipe() {
    $this->database->delete('sync')->execute();
    $this->database->delete('sync_data')->execute();
  }

}
