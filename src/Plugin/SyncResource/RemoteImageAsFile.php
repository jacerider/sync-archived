<?php

namespace Drupal\sync\Plugin\SyncResource;

use Drupal\sync\Plugin\SyncDataItem;
use Drupal\sync\Plugin\SyncResourceFileBase;
use Drupal\sync\SyncFailException;

/**
 * Plugin implementation of the 'remote_image_file' eXo theme.
 *
 * This resource is intended to be used from within another resource.
 *
 * @SyncResource(
 *   id = "sync_remote_image_file",
 *   label = @Translation("Remote Image as File"),
 *   client = "sync_remote_file",
 *   entity_type = "file",
 *   cron = false,
 *   no_ui = true,
 * )
 */
class RemoteImageAsFile extends SyncResourceFileBase {

  /**
   * The ID of the data item.
   *
   * @var string
   */
  protected $id;

  /**
   * The directory the file will be placed into.
   *
   * @var string
   */
  protected $directory = 'public://images';

  /**
   * The file name.
   *
   * @var string
   */
  protected $filename;

  /**
   * {@inheritdoc}
   */
  protected function id(SyncDataItem $item) {
    return !empty($this->id) ? $this->id : md5($this->getRemoteUrl());
  }

  /**
   * Set the id.
   *
   * @param string $id
   *   The id.
   */
  public function setId($id) {
    $this->id = $id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestination(SyncDataItem $item) {
    $directory = $this->getDirectory($item);
    $filename = $this->getFilename($item);
    return !empty($directory) && !empty($filename) ? $directory . '/' . $filename : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDirectory(SyncDataItem $item) {
    return $this->directory;
  }

  /**
   * Set the directory.
   *
   * @param string $directory
   *   The directory.
   */
  public function setDirectory($directory) {
    $this->directory = $directory;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFilename(SyncDataItem $item) {
    return $this->filename;
  }

  /**
   * Set the filename.
   *
   * @param string $filename
   *   The filename.
   */
  public function setFilename($filename) {
    $this->filename = $filename;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setRemoteUrl($url) {
    /** @var \Drupal\sync\Plugin\SyncFetcher\Http $fetcher */
    $fetcher = $this->getFetcher();
    $fetcher->setUrl($url);
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteUrl() {
    /** @var \Drupal\sync\Plugin\SyncFetcher\Http $fetcher */
    $fetcher = $this->getFetcher();
    return $fetcher->getUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareItem(SyncDataItem $item) {
    parent::prepareItem($item);
    if (!$this->id($item)) {
      throw new SyncFailException('Missing required [id] data.');
    }
    if (!$this->getDirectory($item)) {
      throw new SyncFailException('Missing required [directory] data.');
    }
    if (!$this->getFilename($item)) {
      throw new SyncFailException('Missing required [filename] data.');
    }
  }

}
