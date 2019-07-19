<?php
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Queue\RequeueException;

/**
 * @file
 * Drush commands for Sync.
 */

/**
 * Implements hook_drush_command().
 */
function sync_drush_command() {
  $items = [];

  $index['resource_id'] = dt('The machine name of an sync resource.');

  $items['sync'] = [
    'description' => 'Run sync by resource id.',
    'examples' => [
      'drush sync node_index' => dt('Sync the resource with the ID @name.', ['@name' => 'node_index']),
    ],
    'arguments' => $index,
    'aliases' => ['sync'],
  ];

  return $items;
}

/**
 * Prints a list of all search indexes.
 */
function drush_sync($resource_id = NULL) {
  // If no $name provided, abort.
  if (!$resource_id) {
    drush_print(dt('Resource id missing. See help using drush sync --help.'));
    return;
  }

  $manager = \Drupal::service('plugin.manager.sync_resource');
  if ($manager->hasDefinition($resource_id)) {
    $instance = $manager->createInstance($resource_id);
    $instance->run();

    $queue_name = 'sync_item';
    $queue_manager = \Drupal::service('plugin.manager.queue_worker');
    $queue_definition = $queue_manager->getDefinition($queue_name);
    $queue_factory = \Drupal::service('queue');

    // Grab the defined cron queues.
    if (isset($queue_definition['cron'])) {
      // Make sure every queue exists. There is no harm in trying to recreate
      // an existing queue.
      $queue_factory->get($queue_name)->createQueue();

      $queue_worker = $queue_manager->createInstance($queue_name);
      $end = time() + (isset($queue_definition['cron']['time']) ? $queue_definition['cron']['time'] : 15);
      $queue = $queue_factory->get($queue_name);
      $lease_time = isset($queue_definition['cron']['time']) ?: NULL;
      while (time() < $end && ($item = $queue->claimItem($lease_time))) {
        try {
          $queue_worker->processItem($item->data);
          $queue->deleteItem($item);
        }
        catch (RequeueException $e) {
          // The worker requested the task be immediately requeued.
          $queue->releaseItem($item);
        }
        catch (SuspendQueueException $e) {
          // If the worker indicates there is a problem with the whole queue,
          // release the item and skip to the next queue.
          $queue->releaseItem($item);

          watchdog_exception('cron', $e);

          // Skip to the next queue.
          continue;
        }
        catch (\Exception $e) {
          // In case of any other kind of exception, log it and leave the item
          // in the queue to be processed again later.
          watchdog_exception('cron', $e);
        }
      }
    }
  }
  else {
    drush_print(dt('Trying to call a non-existent resource. See help using drush sync --help.'));
  }
}
