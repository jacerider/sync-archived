<?php

namespace Drupal\sync\Plugin;

use Drupal\Core\Entity\EntityInterface;
use Drupal\media\MediaInterface;
use Drupal\sync\SyncFailException;

/**
 * A base resource used for creating files.
 *
 * @ExampleSyncResource(
 *   id = "my_module",
 *   label = @Translation("My Module"),
 *   client = "my_client",
 *   no_ui = true,
 *   entity_type = "media",
 *   bundle = "image",
 * )
 */
abstract class SyncResourceMediaFileBase extends SyncResourceFileBase {

  /**
   * The field that will store the file entity reference.
   *
   * @var string
   */
  protected $mediaFieldName = 'field_media_image';

  /**
   * Get media field where the file entity reference will be stored.
   *
   * @return string
   *   The field name.
   */
  protected function getMediaFieldname(array $data) {
    return $this->mediaFieldName;
  }

  /**
   * {@inheritdoc}
   */
  protected function processItem(EntityInterface $entity, array $data) {
    $this->processItemAsMedia($entity, $data);
  }

  /**
   * {@inheritdoc}
   */
  protected function processItemAsMedia(MediaInterface $entity, $data) {
    $field_name = $this->getMediaFieldname($data);
    if (!$entity->hasField($field_name)) {
      throw new SyncFailException('The media entity does not have a field name ' . $field_name);
    }

    $file = $this->syncEntityProvider->getOrNew($this->id($data), 'file', 'file');
    $this->processItemAsFile($file, $data);
    if ($file->isNew()) {
      $file->save();
    }

    $entity->setName($file->getFilename());
    $entity->get($field_name)->setValue([
      'target_id' => $file->id(),
    ]);

  }

}
