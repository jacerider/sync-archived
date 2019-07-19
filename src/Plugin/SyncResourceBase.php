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
   * The fetcher queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queueFetcher;

  /**
   * The item queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queueItem;

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
   * If run should 'run' event when data is empty.
   *
   * @var int
   */
  protected $runOnEmpty = FALSE;

  /**
   * The maximum number of items to show when debugging.
   *
   * @var int
   */
  protected $maxDebug = 100;

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
   * @param \Drupal\Core\Queue\QueueInterface $queue_fetcher
   *   The fetcher queue object.
   * @param \Drupal\Core\Queue\QueueInterface $queue_item
   *   The item queue object.
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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger, QueueInterface $queue_fetcher, QueueInterface $queue_item, SyncClientManagerInterface $sync_client_manager, SyncFetcherManager $sync_fetcher_manager, SyncParserManager $sync_parser_manager, SyncStorageInterface $sync_storage, SyncEntityProviderInterface $sync_entity_provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger->get('sync');
    $this->queueFetcher = $queue_fetcher;
    $this->queueItem = $queue_item;
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
      $container->get('queue')->get('sync_fetcher'),
      $container->get('queue')->get('sync_item'),
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
  public function getBundle(array $data) {
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
  public function getLastStart() {
    return \Drupal::service('plugin.manager.sync_resource')->getLastRunStart($this->pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public function getLastEnd() {
    return \Drupal::service('plugin.manager.sync_resource')->getLastRunEnd($this->pluginDefinition);
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
    $this->logger->notice('[Sync Queue: %plugin_label] START: %entity_type.', $this->getContext());
    try {
      $data = $this->getData($additional + [
        '_pager_finish' => 'run',
      ]);
      if (!empty($data) || $this->shouldRunOnEmpty()) {
        $this->runData($data, $additional);
      }
    }
    catch (SyncFetcherPagingException $e) {
      // Do nothing.
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function runData($data, array $additional = []) {
    $start = \Drupal::time()->getRequestTime();
    $this->queueItem->createItem([
      'plugin_id' => $this->getPluginId(),
      'op' => 'start',
      'no_count' => TRUE,
      'data' => [
        'start' => $start,
      ] + $additional,
    ]);
    foreach ($data as $item_data) {
      $item = [
        'plugin_id' => $this->getPluginId(),
        'op' => 'process',
        'data' => $item_data + $additional,
      ];
      $this->queueItem->createItem($item);
    }
    if ($this->usesCleanup()) {
      $this->queueItem->createItem([
        'plugin_id' => $this->getPluginId(),
        'op' => 'cleanup',
        'no_count' => TRUE,
        'data' => [
          'start' => $start,
        ] + $additional,
      ]);
    }
    $this->queueItem->createItem([
      'plugin_id' => $this->getPluginId(),
      'op' => 'end',
      'no_count' => TRUE,
      'data' => [
        'start' => $start,
      ] + $additional,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function start(array $data) {
    \Drupal::service('plugin.manager.sync_resource')->setLastRunStart($this->pluginDefinition, $data['start']);
  }

  /**
   * {@inheritdoc}
   */
  public function end(array $data) {
    \Drupal::service('plugin.manager.sync_resource')->setLastRunEnd($this->pluginDefinition);
  }

  /**
   * Determine if sync should run even if there are no results.
   */
  protected function shouldRunOnEmpty() {
    return $this->runOnEmpty;
  }

  /**
   * {@inheritdoc}
   */
  public function runAsBatch() {
    $this->run([
      '_sync_as_batch' => TRUE,
      '_pager_finish' => 'runAsBatch',
    ]);
    $this->buildBatch($this->queueItem);
  }

  /**
   * {@inheritdoc}
   */
  public function buildBatch(QueueInterface $queue, $item_callback = 'runBatch', $finish_callback = 'finishItemBatch', $title = 'Importing Data') {
    $item_count = $queue->numberOfItems();
    if ($item_count) {
      $class_name = get_class($this);
      $batch = [
        'title' => t('Batch: %title', ['%title' => $title]),
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
          [$class_name, $item_callback],
          [],
        ];
      }
      batch_set($batch);
    }
  }

  /**
   * Runs a batch callback.
   *
   * @param array $context
   *   The batch context.
   * @param string $queue_id
   *   The queue_id of the queue and queue worker that should be ran.
   */
  public static function runBatch(array &$context, $queue_id = 'sync_item') {
    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = \Drupal::service('queue')->get($queue_id);
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $queue_worker */
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance($queue_id);
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
        drupal_set_message($e->getMessage(), 'error');
        $queue->deleteItem($item);
        $context['results']['skip']++;
      }
      catch (SuspendQueueException $e) {
        drupal_set_message($e->getMessage(), 'error');
        $queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $context['results']['fail']++;
        }
      }
      catch (\Exception $e) {
        drupal_set_message($e->getMessage(), 'error');
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
  public static function finishItemBatch($success, array $results, array $operations, $elapsed) {
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
  public static function finishCleanupBatch($success, array $results, array $operations, $elapsed) {
  }

  /**
   * Get the data.
   *
   * @return array
   *   An array of data.
   */
  protected function getData($additional = [], $client_id = NULL, $fetcher_settings = [], $parser_settings = []) {
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

    $data = $this->loadData($client, $fetcher, $parser);
    if ($fetcher->supportsPaging() && empty($additional['_pager_skip'])) {
      $tempstore = $this->getTempStore();
      // Reset temp storage.
      // $tempstore->delete('page');
      $this->resetPageCount();
      $tempstore->delete('data');
      // Check if we have already loaded all data.
      if ($page_data = $tempstore->get('final_data')) {
        $tempstore->delete('final_data');
        return $page_data;
      }
      $this->addPage($fetcher, $parser, $additional, $data);
      $this->buildBatch($this->queueFetcher, 'runPageBatch', 'finishPageBatch', 'Fetching Data');
      throw new SyncFetcherPagingException();
    }
    return $this->loadData($client, $fetcher, $parser);
  }

  /**
   * Load the data.
   *
   * @return array
   *   An array of data.
   */
  protected function loadData(array $client, SyncFetcherInterface $fetcher, SyncParserInterface $parser) {
    $this->alterParser($parser);
    try {
      $this->logger->notice('[Sync Data: %plugin_label] GET: %entity_type.', $this->getContext());
      $data = $fetcher->fetch();
      $data = $parser->parse($data);
      $data = $this->filter($data);
      return $data;
    }
    catch (\Exception $e) {
      $this->logger->error('[Sync Data: %plugin_label] ERROR: %entity_type: @message.', $this->getContext() + [
        '@message' => $e->getMessage(),
      ]);
      drupal_set_message($e->getMessage(), 'error');
    }
    return [];
  }

  /**
   * Get the temp storage service.
   */
  protected function getTempStore() {
    return \Drupal::service('user.shared_tempstore')->get('sync');
  }

  /**
   * Get the state key.
   *
   * @return string
   *   The state key.
   */
  protected function getStateKey() {
    return 'sync.resource.' . $this->pluginId;
  }

  /**
   * Set the page count.
   */
  protected function getPageCount() {
    $count = \Drupal::state()->get($this->getStateKey() . '.page');
    return $count ? $count : 1;
  }

  /**
   * Get the page count.
   */
  protected function incrementPageCount() {
    $count = $this->getPageCount();
    \Drupal::state()->set($this->getStateKey() . '.page', $count + 1);
    return $this;
  }

  /**
   * Reset the page count.
   */
  protected function resetPageCount() {
    \Drupal::state()->delete($this->getStateKey() . '.page');
    return $this;
  }

  /**
   * Add a pager batch item.
   */
  protected function addPage(SyncFetcherInterface $fetcher, SyncParserInterface $parser, $additional = [], $new_data = []) {
    $context = $this->getContext();
    $tempstore = $this->getTempStore();
    // $page = $tempstore->get('page') ? $tempstore->get('page') : 1;
    $page = $this->getPageCount();
    $data = $tempstore->get('data') ? $tempstore->get('data') : [];
    $data = array_merge($data, $new_data);
    $context['%page'] = $page;
    $context['%data_count'] = count($data);
    $context['%new_data_count'] = count($new_data);
    if (empty($new_data)) {
      $this->logger->notice('[Sync Item: %plugin_label] PAGE FINISH: %entity_type with %new_data_count records added for a total of %data_count.', $context);
      $tempstore->set('final_data', $data);
      // $tempstore->delete('page');
      $this->resetPageCount();
      $tempstore->delete('data');
      if (!empty($additional['_pager_finish']) && method_exists($this, $additional['_pager_finish'])) {
        // Call original method which will use stored data and continue.
        $this->{$additional['_pager_finish']}();
      }
      return;
    }
    $this->logger->notice('[Sync Item: %plugin_label] PAGE %page: %entity_type with %new_data_count records added for a total of %data_count.', $context);
    $this->queueFetcher->createItem([
      'plugin_id' => $this->getPluginId(),
      'op' => 'loadPageData',
      'data' => [
        'page' => $page,
        'data' => $data,
        'additional' => $additional,
        'fetcher' => $fetcher,
        'parser' => $parser,
      ],
    ]);
    // $tempstore->set('page', $page + 1);
    $this->incrementPageCount();
    $tempstore->set('data', $data);
    if (!empty($additional['_sync_as_batch'])) {
      $this->buildBatch($this->queueFetcher, 'runPageBatch', 'finishPageBatch', 'Fetching Data: Batch ' . $page);
    }
    else {
      $context = [];
      $this->runPageBatch($context);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadPageData(array $batch_data) {
    $fetcher = $batch_data['fetcher'];
    $parser = $batch_data['parser'];
    $page = $batch_data['page'];
    $data = $batch_data['data'];
    $additional = $batch_data['additional'];
    $new_data = $fetcher->fetchPage($data, $page);
    $new_data = $parser->parse($new_data);
    $new_data = $this->filter($new_data);
    $this->addPage($fetcher, $parser, $additional, $new_data);
  }

  /**
   * Runs a single user batch import.
   *
   * @param array $context
   *   The batch context.
   */
  public static function runPageBatch(array &$context) {
    static::runBatch($context, 'sync_fetcher');
  }

  /**
   * Callback executed when paging has completed.
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
  public static function finishPageBatch($success, array $results, array $operations, $elapsed) {
  }

  /**
   * {@inheritdoc}
   */
  public function filter(array $data) {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function loadEntity(array $data) {
    return $this->syncEntityProvider->getOrNew($this->id($data), $this->getEntityType(), $this->getBundle($data), [], $this->getGroup());
  }

  /**
   * {@inheritdoc}
   */
  public function process(array $data) {
    $context = $this->getContext($data);
    $id = $this->id($data);
    $context['%id'] = $id;
    $entity = $this->loadEntity($data);
    try {
      $this->logger->notice('[Sync Item: %plugin_label] START: %id for %entity_type:%bundle', $context);
      if ($entity) {
        if ($this->syncAccess($entity)) {
          $this->processItem($entity, $data);
          $success = $this->save($id, $entity, $data);
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
      // We need to make sure we have updated this record when skipping.
      $this->syncStorage->save($entity->__sync_id, $entity, FALSE, $entity->__sync_group);
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
   * @param array $data
   *   The data provided from the Unionware API for a single item.
   *
   * @return bool
   *   A bool representing success.
   */
  protected function save($id, ContentEntityInterface $entity, array $data) {
    return $entity->save();
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
          $this->queueItem->createItem([
            'plugin_id' => $this->getPluginId(),
            'op' => 'clean',
            'data' => [
              'sync' => $sync,
              'data' => $data,
            ],
          ]);
        }
        if (!empty($data['_sync_as_batch'])) {
          $this->buildBatch($this->queueItem, 'runBatch', 'finishCleanupBatch');
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
    try {
      $data = $this->getData([
        '_pager_skip' => TRUE,
      ]);
      if (!empty($data) && count($data) > $this->maxDebug) {
        $data = array_slice($data, 0, $this->maxDebug);
      }
      if (\Drupal::service('module_handler')->moduleExists('kint')) {
        ksm($data);
      }
      else {
        print '<pre>' . print_r($data, FALSE) . '</pre>';
        die;
      }
    }
    catch (SyncFetcherPagingException $e) {
      // Do nothing.
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
    ];
    if (!empty($data)) {
      $context['%bundle'] = $this->getBundle($data);
    }
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
