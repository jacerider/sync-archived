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
   * Return the destination directory.
   *
   * @return string
   *   The URI as a string. Example: public://files
   */
  protected function getDirectory(SyncDataItem $item) {
    return \Drupal::config('system.file')->get('default_scheme') . '://';
  }

  /**
   * Return the filename.
   *
   * @return string
   *   The URI as a string. Example: myfile.jpg
   */
  protected function getFilename(SyncDataItem $item) {
    return 'file.txt';
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
  protected function getReplaceBehavior(SyncDataItem $item) {
    return FileSystemInterface::EXISTS_RENAME;
  }

  /**
   * {@inheritdoc}
   */
  protected function processItem(EntityInterface $entity, SyncDataItem $item) {
    $this->processItemAsFile($entity, $item);
  }

  /**
   * Process a file entity.
   */
  protected function processItemAsFile(FileInterface $entity, SyncDataItem $item) {
    $directory = $this->getDirectory($item);
    $filename = $this->getFilename($item);
    $destination = $directory . '/' . $filename;
    if (!\Drupal::service('stream_wrapper_manager')->isValidUri($destination)) {
      throw new SyncFailException('The data could not be saved because the destination ' . $destination . 'is invalid. This may be caused by improper use of file_save_data() or a missing stream wrapper.');
    }
    if ($entity->isNew()) {
      $replace = $this->getReplaceBehavior($item);
      /** @var \Drupal\Core\File\FileSystemInterface $file_system */
      $file_system = \Drupal::service('file_system');
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
      $uri = $file_system->saveData($item['contents'], $destination, $replace);
      $entity->setFileUri($uri);
      $entity->setFilename($file_system->basename($uri));
      $entity->setMimeType(\Drupal::service('file.mime_type.guesser')->guess($uri));

      // If we are replacing an existing file re-use its database record.
      // @todo Do not create a new entity in order to update it. See
      //   https://www.drupal.org/node/2241865.
      if ($replace == FileSystemInterface::EXISTS_REPLACE) {
        $existing_files = $this->entityTypeManager->getStorage('file')->loadByProperties([
          'uri' => $uri,
        ]);
        if (count($existing_files)) {
          /** @var \Drupal\file\FileInterface $existing */
          $existing = reset($existing_files);
          $entity->fid = $existing->id();
          $entity->setOriginalId($existing->id());
          $entity->setFilename($existing->getFilename());
        }
      }
      elseif ($replace == FileSystemInterface::EXISTS_RENAME && is_file($destination)) {
        $entity->setFilename($file_system->basename($destination));
      }

      $entity->set('status', FILE_STATUS_PERMANENT);
    }
    else {
      file_put_contents($entity->getFileUri(), $item['contents']);
      if ($destination != $entity->getFileUri()) {
        $replace = $this->getReplaceBehavior($item);
        file_move($entity, $destination, $replace);
      }
    }
  }

}
