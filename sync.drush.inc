<?php

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
    $queue_name = 'sync';
    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get($queue_name);
    $queue->deleteQueue();

    $instance = $manager->createInstance($resource_id);
    $instance->run(['_sync_as_batch' => TRUE]);

    while ($queue->numberOfItems() > 0) {
      $context = [];
      $instance->runBatch($context);
    }
  }
  else {
    drush_print(dt('Trying to call a non-existent resource. See help using drush sync --help.'));
  }
}
