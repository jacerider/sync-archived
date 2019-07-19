<?php

namespace Drupal\sync\Plugin\QueueWorker;

/**
 * Process a queue of Sync items to process their data.
 *
 * @QueueWorker(
 *   id = "sync_fetcher",
 *   title = @Translation("Sync Fetcher"),
 *   cron = {"time" = 180}
 * )
 */
class SyncFetcher extends SyncQueueWorkerBase {

}
