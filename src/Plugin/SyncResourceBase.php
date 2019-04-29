<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sync\SyncClientManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\sync\SyncHaltException;
use Drupal\sync\SyncEntityProviderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sync\SyncStorageInterface;

/**
 * Base class for Sync Resource plugins.
 */
abstract class SyncResourceBase extends PluginBase implements SyncResourceInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The sync client manager.
   *
   * @var \Drupal\sync\SyncClientManagerInterface
   */
  protected $syncClientManager;

  /**
   * The sync fetcher manager.
   *
   * @var \Drupal\sync\SyncFetcherManager
   */
  protected $syncFetcherManager;

  /**
   * The sync parser manager.
   *
   * @var \Drupal\sync\SyncParserManager
   */
  protected $syncParserManager;

  /**
   * The sync storage.
   *
   * @var \Drupal\sync\SyncStorageInterface
   */
  protected $syncStorage;

  /**
   * The sync entity provider.
   *
   * @var \Drupal\sync\SyncEntityProviderInterface
   */
  protected $syncEntityProvider;

  /**
   * Constructs a SyncResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue object.
   * @param \Drupal\sync\SyncClientManagerInterface $sync_client_manager
   *   The sync client manager.
   * @param \Drupal\sync\SyncFetcherManager $sync_fetcher_manager
   *   The sync fetcher manager.
   * @param \Drupal\sync\SyncParserManager $sync_parser_manager
   *   The sync parser manager.
   * @param \Drupal\sync\SyncStorageInterface $sync_storage
   *   The sync storage.
   * @param \Drupal\sync\SyncEntityProviderInterface $sync_entity_provider
   *   The sync entity provider.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger, QueueInterface $queue, SyncClientManagerInterface $sync_client_manager, SyncFetcherManager $sync_fetcher_manager, SyncParserManager $sync_parser_manager, SyncStorageInterface $sync_storage, SyncEntityProviderInterface $sync_entity_provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger->get('sync');
    $this->queue = $queue;
    $this->syncClientManager = $sync_client_manager;
    $this->syncFetcherManager = $sync_fetcher_manager;
    $this->syncParserManager = $sync_parser_manager;
    $this->syncStorage = $sync_storage;
    $this->syncEntityProvider = $sync_entity_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('queue')->get('sync'),
      $container->get('plugin.manager.sync_client'),
      $container->get('plugin.manager.sync_fetcher'),
      $container->get('plugin.manager.sync_parser'),
      $container->get('sync.storage'),
      $container->get('sync.entity_provider')
    );
  }

  /**
   * The value to use as the unique ID.
   *
   * @param array $data
   *   The data for a single item.
   *
   * @return string
   *   The value to use as the unique ID.
   */
  abstract protected function id(array $data);

  /**
   * {@inheritdoc}
   */
  public function label() {
    $definition = $this->getPluginDefinition();
    return (string) $definition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType() {
    $definition = $this->getPluginDefinition();
    return $definition['entity_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    $definition = $this->getPluginDefinition();
    return $definition['bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup() {
    return $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function usesCleanup() {
    $definition = $this->getPluginDefinition();
    return $definition['cleanup'] == TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $additional = []) {
    $this->logger->notice('[Sync Queue: %plugin_label] START: %entity_type:%bundle.', $this->getContext());
    $start = \Drupal::time()->getRequestTime();
    foreach ($this->getData() as $data) {
      $item = [
        'plugin_id' => $this->getPluginId(),
        'op' => 'process',
        'data' => $data + $additional,
      ];
      $this->queue->createItem($item);
    }
    if ($this->usesCleanup()) {
      $this->queue->createItem([
        'plugin_id' => $this->getPluginId(),
        'op' => 'cleanup',
        'no_count' => TRUE,
        'data' => [
          'start' => $start,
        ] + $additional,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function runAsBatch() {
    $this->run(['_sync_as_batch' => TRUE]);
    $this->buildBatch();
  }

  /**
   * {@inheritdoc}
   */
  public function buildBatch($finish_callback = 'finishBatch') {
    $item_count = $this->queue->numberOfItems();
    if ($item_count) {
      $class_name = get_class($this);
      $batch = [
        'title' => t('Importing Data...'),
        'operations' => [],
        'init_message' => t('Commencing'),
        'progress_message' => t('Please do not close this window until import is complete. Task @current out of @total.'),
        'error_message' => t('An error occurred during processing'),
        'finished' => [
          $class_name, $finish_callback,
        ],
      ];
      for ($i = 0; $i < $item_count; $i++) {
        $batch['operations'][] = [
          [$class_name, 'runBatch'],
          [],
        ];
      }
      batch_set($batch);
    }
  }

  /**
   * Runs a single user batch import.
   *
   * @param array $context
   *   The batch context.
   */
  public static function runBatch(array &$context) {
    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = \Drupal::service('queue')->get('sync');
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $queue_worker */
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('sync');
    $item = $queue->claimItem();
    if ($item) {
      $context['results']['success'] = $context['results']['success'] ?? 0;
      $context['results']['skip'] = $context['results']['skip'] ?? 0;
      $context['results']['fail'] = $context['results']['fail'] ?? 0;
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $context['results']['success']++;
        }
      }
      catch (SyncHaltException $e) {
        $queue->deleteItem($item);
        $context['results']['skip']++;
      }
      catch (SuspendQueueException $e) {
        $queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $context['results']['fail']++;
        }
      }
      catch (\Exception $e) {
        $queue->deleteItem($item);
        watchdog_exception('sync', $e);
        if (empty($item->data['no_count'])) {
          $context['results']['fail']++;
        }
      }
    }
  }

  /**
   * Callback executed when creation has completed.
   *
   * @param bool $success
   *   TRUE if batch successfully completed.
   * @param array $results
   *   Batch results.
   * @param array $operations
   *   An array of methods run in the batch.
   * @param string $elapsed
   *   The time to run the batch.
   */
  public static function finishBatch($success, array $results, array $operations, $elapsed) {
    $success = $results['success'] ?? 0;
    $skip = $results['skip'] ?? 0;
    $fail = $results['fail'] ?? 0;
    drupal_set_message(t('The data import has completed. %success items were successful, %skip items were skipped, and %fail items failed.', [
      '%success' => $success,
      '%skip' => $skip,
      '%fail' => $fail,
    ]));
  }

  /**
   * Get the data.
   *
   * @return array
   *   An array of data.
   */
  protected function getData($client_id = NULL, $fetcher_settings = [], $parser_settings = []) {
    // Get client.
    $client_id = $client_id ?: $this->getPluginDefinition()['client'];
    $client = $this->syncClientManager->getDefinition($client_id);
    // Get fetcher.
    $settings = NestedArray::mergeDeep($client['fetcher_settings'], $fetcher_settings);
    $fetcher = $this->syncFetcherManager->createInstance($client['fetcher'], $settings);
    $this->alterFetcher($fetcher);
    // Get parser.
    $settings = NestedArray::mergeDeep($client['parser_settings'], $parser_settings);
    $parser = $this->syncParserManager->createInstance($client['parser'], $settings);
    $this->alterParser($parser);
    try {
      $this->logger->notice('[Sync Data: %plugin_label] GET: %entity_type:%bundle.', $this->getContext());
      $data = $fetcher->fetch();
      $data = $parser->parse($data);
      return $data;
    }
    catch (\Exception $e) {
      $this->logger->error('[Sync Data: %plugin_label] ERROR: %entity_type:%bundle: @message.', $this->getContext() + [
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadEntity(array $data) {
    return $this->syncEntityProvider->getOrNew($this->id($data), $this->getEntityType(), $this->getBundle(), [], $this->getGroup());
  }

  /**
   * {@inheritdoc}
   */
  public function process(array $data) {
    $context = $this->getContext($data);
    $id = $this->id($data);
    $context['%id'] = $id;
    try {
      $this->logger->notice('[Sync Item: %plugin_label] START: %id for %entity_type:%bundle', $context);
      $entity = $this->loadEntity($data);
      if ($entity) {
        if ($this->syncAccess($entity)) {
          $this->processItem($entity, $data);
          $success = $this->save($id, $entity);
          switch ($success) {
            case SAVED_NEW:
              $this->logger->notice('[Sync Item: %plugin_label] NEW: %id for %entity_type:%bundle', $context);
              break;

            case SAVED_UPDATED:
              $this->logger->notice('[Sync Item: %plugin_label] UPDATED: %id for %entity_type:%bundle', $context);
              break;

            default:
              $this->logger->notice('[Sync Item: %plugin_label] SUCCESS: %id for %entity_type:%bundle', $context);
              break;
          }
        }
        else {
          // We save the entity to make sure it is not queued for cleanup.
          if (!$entity->isNew()) {
            $entity->save();
          }
          throw new SyncHaltException('Entity could not be loaded or sync access was halted.');
        }
      }
      else {
        throw new \Exception('Entity could not be loaded.');
      }
    }
    catch (SyncHaltException $e) {
      $this->logger->notice('[Sync Item Skip: %plugin_label] SKIP: %id for %entity_type:%bundle', $context);
      if ($data['_sync_as_batch']) {
        // Send up to runBatch.
        throw new SyncHaltException($e->getMessage());
      }
    }
    catch (\Exception $e) {
      $context['%error'] = $e->getMessage();
      $this->logger->error('[Sync Item: %plugin_label] FAIL: %id for %entity_type:%bundle with data %data. Error: %error', $context);
      if ($data['_sync_as_batch']) {
        throw new \Exception($e->getMessage());
      }
    }
  }

  /**
   * Extending classes should provide the item process method.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to process.
   * @param array $data
   *   The data provided from the Unionware API for a single item.
   */
  abstract protected function processItem(ContentEntityInterface $entity, array $data);

  /**
   * Save the entity and store record in sync table.
   *
   * @param string $id
   *   The sync id.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to process.
   *
   * @return bool
   *   A bool representing success.
   */
  protected function save($id, ContentEntityInterface $entity) {
    return $this->syncEntityProvider->save($id, $entity, $this->getGroup());
  }

  /**
   * {@inheritdoc}
   */
  public function syncAccess(ContentEntityInterface $entity) {
    return empty($entity->syncIsLocked);
  }

  /**
   * {@inheritdoc}
   */
  public function cleanup(array $data) {
    $context = $this->getContext($data);
    try {
      $query = $this->syncStorage->getDataQuery($this->getGroup());
      $this->cleanupQueryAlter($query, $data);
      $results = $query->execute()->fetchAllAssoc('id');
      if (!empty($results)) {
        foreach ($results as $sync) {
          $this->queue->createItem([
            'plugin_id' => $this->getPluginId(),
            'op' => 'clean',
            'data' => [
              'sync' => $sync,
              'data' => $data,
            ],
          ]);
        }
        if (!empty($data['_sync_as_batch'])) {
          $this->buildBatch('finishCleanupBatch');
        }
      }
    }
    catch (\Exception $e) {
      $context['%error'] = $e->getMessage();
      $this->logger->error('[Sync Cleanup: %plugin_label] FAIL: %entity_type:%bundle with data %data. Error: %error', $context);
      if ($data['_sync_as_batch']) {
        throw new \Exception($e->getMessage());
      }
    }
  }

  /**
   * Allow altering of cleanup query.
   */
  protected function cleanupQueryAlter($query, array $data) {
    $query->condition('sync_data.changed', $data['start'], '<');
    $query->condition('sync.locked', 0);
  }

  /**
   * {@inheritdoc}
   */
  public function clean(array $data) {
    $sync = $data['sync'];
    $data = $data['data'];
    $context = $this->getContext($data);
    $context['%id'] = $sync->id;
    try {
      $entity = $this->syncStorage->loadEntity($sync->id, $sync->entity_type);
      if ($entity) {
        if ($this->syncAccess($entity)) {
          $this->cleanExecute($entity, $sync, $context);
        }
        else {
          throw new SfHaltException('Entity could not be loaded or sync access was halted.');
        }
      }
      else {
        throw new \Exception('Entity could not be loaded.');
      }
    }
    catch (SfHaltException $e) {
      $this->logger->notice('[Sync Clean Skip: %plugin_label] SKIP: %id for %entity_type:%bundle', $context);
      if ($data['_sync_as_batch']) {
        // Send up to runBatch.
        throw new SfHaltException($e->getMessage());
      }
    }
    catch (\Exception $e) {
      $context['%error'] = $e->getMessage();
      $this->logger->error('[Sync Clean: %plugin_label] FAIL: %id for %entity_type:%bundle with data %data. Error: %error', $context);
      if ($data['_sync_as_batch']) {
        throw new \Exception($e->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cleanExecute(ContentEntityInterface $entity, $sync, $context) {
    $status = $entity->delete();
    if ($status) {
      // Entity cleanup is handled in hook_entity_delete().
      $this->logger->notice('[Sync Clean: %plugin_label] DELETE: %entity_id for %entity_type:%bundle', $context);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterFetcher(SyncFetcherInterface $request) {
  }

  /**
   * {@inheritdoc}
   */
  public function alterParser(SyncParserInterface $parser) {
  }

  /**
   * {@inheritdoc}
   */
  public function debug() {
    $data = $this->getData();
    if (\Drupal::service('module_handler')->moduleExists('kint')) {
      ksm($data);
    }
    else {
      print '<pre>' . print_r($data, FALSE) . '</pre>';
      die;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getContext(array $data = []) {
    $context = [
      '%plugin_id' => $this->getPluginId(),
      '%plugin_label' => $this->label(),
      '%entity_type' => $this->getEntityType(),
      '%bundle' => $this->getBundle(),
    ];
    if (!empty($data)) {
      $context += [
        '%data' => print_r($data, TRUE),
      ];
    }
    return $context;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildMedia($entity, $files, $field_name, $media_field_name, $media_type, $media_properties = [], $media_field_properties = []) {
    $values = [];
    if (empty($files)) {
      return $values;
    }

    if ($entity->hasField($field_name)) {
      $field = $entity->get($field_name);
      $referenced_entities = $field->referencedEntities();
      // Remove extra entities in case the files have decreased.
      $remove = array_diff_key($referenced_entities, $files);
      foreach ($remove as $delta => $media) {
        if ($media->hasField($media_field_name)) {
          $media_field = $media->get($media_field_name);
          // If media already has a file set, we need to delete it.
          if (!$media_field->isEmpty() && $media_field->entity) {
            $media_field->entity->delete();
          }
        }
        $media->delete();
      }
      foreach ($files as $delta => $file) {
        if (isset($referenced_entities[$delta])) {
          $media = $referenced_entities[$delta];
        }
        else {
          $media = $this->entityTypeManager->getStorage('media')->create([
            'bundle' => $media_type,
            'uid' => 1,
            'status' => 1,
          ]);
        }
        foreach ($media_properties as $name => $value) {
          $media->set($name, $value);
        }
        if ($media->hasField($media_field_name)) {
          $media_field = $media->get($media_field_name);
          // If media already has a file set, we need to delete it.
          if (!$media_field->isEmpty() && $media_field->entity) {
            $media_field->entity->delete();
          }
          // If we have a file we want to assign it to the media.
          if ($file) {
            $media_field->setValue([
              'target_id' => $file->id(),
            ] + $media_field_properties);
            $media->save();
            $values[$delta] = [
              'target_id' => $media->id(),
            ];
          }
          elseif (!$media->isNew()) {
            // We do not have an updated file and we want to delete it.
            $media->delete();
          }
        }

      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function cleanMedia($entity, $field_name, $media_field_name) {
    if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
      foreach ($entity->get($field_name)->referencedEntities() as $media) {
        if ($media && $media->hasField($media_field_name) && !$media->get($media_field_name)->isEmpty()) {
          $file = $media->get($media_field_name)->entity;
          if ($file) {
            $file->delete();
          }
        }
        $media->delete();
      }
    }
  }

}
