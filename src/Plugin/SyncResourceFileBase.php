<?php

namespace Drupal\sync\Plugin;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\sync\SyncFailException;

/**
 * A base resource used for creating files.
 *
 * @ExampleSyncResource(
 *   id = "my_module",
 *   label = @Translation("My Module"),
 *   client = "my_client",
 *   no_ui = true,
 *   entity_type = "file",
 * )
 */
abstract class SyncResourceFileBase extends SyncResourceBase {

  /**
   * Return the destination.
   *
   * @return string
   *   The URI as a string. Example: public://myfile.jpg
   */
  protected function getDestination(array $data) {
    return file_default_scheme() . '://';
  }

  /**
   * Return the replacement behavior.
   *
   * EXISTS_REPLACE: Replace the existing file. If a managed file with
   *   the destination name exists, then its database entry will be updated. If
   *   no database entry is found, then a new one will be created.
   * EXISTS_RENAME: (default) Append _{incrementing number} until the
   *   filename is unique.
   * EXISTS_ERROR: Do nothing and return FALSE.
   *
   * @return string
   *   The URI as a string. Example: public://myfile.jpg
   */
  protected function getReplaceBehavior(array $data) {
    return FileSystemInterface::EXISTS_RENAME;
  }

  /**
   * {@inheritdoc}
   */
  protected function processItem(EntityInterface $entity, array $data) {
    $this->processItemAsFile($entity, $data);
  }

  /**
   * Process a file entity.
   */
  protected function processItemAsFile(FileInterface $entity, array $data) {
    $destination = $this->getDestination($data);
    if (!file_valid_uri($destination)) {
      throw new SyncFailException('The data could not be saved because the destination ' . $destination . 'is invalid. This may be caused by improper use of file_save_data() or a missing stream wrapper.');
    }
    if ($entity->isNew()) {
      $replace = $this->getReplaceBehavior($data);
      $file_system = \Drupal::service('file_system');
      $uri = $file_system->saveData($data['contents'], $destination, $replace);
      $entity->setFileUri($uri);
      $entity->setFilename($file_system->basename($uri));
      $entity->setMimeType(\Drupal::service('file.mime_type.guesser')->guess($uri));

      // If we are replacing an existing file re-use its database record.
      // @todo Do not create a new entity in order to update it. See
      //   https://www.drupal.org/node/2241865.
      if ($replace == FileSystemInterface::EXISTS_REPLACE) {
        $existing_files = $this->entityTypeManager()->getStorage('file')->loadByProperties([
          'uri' => $uri,
        ]);
        if (count($existing_files)) {
          $existing = reset($existing_files);
          $entity->fid = $existing->id();
          $entity->setOriginalId($existing->id());
          $entity->setFilename($existing->getFilename());
        }
      }
      elseif ($replace == FileSystemInterface::EXISTS_RENAME && is_file($destination)) {
        $entity->setFilename(drupal_basename($destination));
      }

      $entity->set('status', FILE_STATUS_PERMANENT);
    }
    else {
      file_put_contents($entity->getFileUri(), $data['contents']);
      if ($destination != $entity->getFileUri()) {
        $replace = $this->getReplaceBehavior($data);
        file_move($entity, $destination, $replace);
      }
    }
  }

}
