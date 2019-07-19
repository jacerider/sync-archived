<?php

namespace Drupal\sync\Plugin\QueueWorker;

/**
 * Process a queue of Sync items to process their data.
 *
 * @QueueWorker(
 *   id = "sync_item",
 *   title = @Translation("Sync Item"),
 *   cron = {"time" = 180}
 * )
 */
class SyncItem extends SyncQueueWorkerBase {

}
