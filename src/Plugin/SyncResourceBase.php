<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sync\SyncClientManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\sync\SyncSkipException;
use Drupal\sync\SyncFailException;
use Drupal\sync\SyncEntityProviderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sync\SyncStorageInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LogLevel;

/**
 * Base class for Sync Resource plugins.
 */
abstract class SyncResourceBase extends PluginBase implements SyncResourceInterface, ContainerFactoryPluginInterface {

  /**
   * The sync client definition.
   *
   * @var array
   */
  protected $client;

  /**
   * The sync fetcher.
   *
   * @var \Drupal\sync\Plugin\SyncFetcherInterface
   */
  protected $fetcher;

  /**
   * The sync parser.
   *
   * @var \Drupal\sync\Plugin\SyncParserInterface
   */
  protected $parser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The state storage service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

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
   * If run should 'run' event when data is empty.
   *
   * @var bool
   */
  protected $runOnEmpty = FALSE;

  /**
   * If verbose debugging should happen.
   *
   * @var bool
   */
  protected $verboseLog;

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
   * @param \Drupal\Core\State\StateInterface $state
   *   The state storage service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \Drupal\Core\Queue\QueueInterface $queue
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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, StateInterface $state, LoggerChannelFactoryInterface $logger, QueueInterface $queue, SyncClientManagerInterface $sync_client_manager, SyncFetcherManager $sync_fetcher_manager, SyncParserManager $sync_parser_manager, SyncStorageInterface $sync_storage, SyncEntityProviderInterface $sync_entity_provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
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
      $container->get('state'),
      $container->get('logger.factory'),
      $container->get('queue')->get('sync_' . $plugin_id),
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
    return !empty($definition['bundle']) ? $definition['bundle'] : $this->getEntityType();
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(array $data) {
    return $this->getPluginId();
  }

  /**
   * The sync client.
   *
   * @return array
   *   The client definition.
   */
  public function getClient() {
    if (!isset($this->client)) {
      $this->client = $this->syncClientManager->getDefinition($this->getPluginDefinition()['client']);
    }
    return $this->client;
  }

  /**
   * The sync fetcher.
   *
   * @return \Drupal\sync\Plugin\SyncFetcherInterface
   *   The sync fetcher plugin.
   */
  public function getFetcher() {
    if (!isset($this->fetcher)) {
      $client = $this->getClient();
      $this->fetcher = $this->syncFetcherManager->createInstance($client['fetcher'], $client['fetcher_settings']);
      $this->alterFetcher($this->fetcher);
    }
    return $this->fetcher;
  }

  /**
   * The sync parser.
   *
   * @return \Drupal\sync\Plugin\SyncParserInterface
   *   The sync fetcher plugin.
   */
  public function getParser() {
    if (!isset($this->parser)) {
      $client = $this->getClient();
      $this->parser = $this->syncParserManager->createInstance($client['parser'], $client['parser_settings']);
      $this->alterParser($this->parser);
    }
    return $this->parser;
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
  public function usesReset() {
    $definition = $this->getPluginDefinition();
    return $definition['reset'] == TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $additional = []) {
    // Reset queue.
    $this->queue->deleteQueue();
    $this->log(LogLevel::NOTICE, '[Sync Start: %plugin_label]', $this->getContext());
    $this->setStartTime();
    $this->fetchData($additional);
  }

  /**
   * {@inheritdoc}
   */
  public function runAsBatch() {
    $this->run([
      '_sync_as_batch' => TRUE,
    ]);
    $this->buildBatch();
  }

  /**
   * {@inheritdoc}
   */
  protected function queueStart(array $additional = []) {
    $this->queue->createItem([
      'plugin_id' => $this->getPluginId(),
      'op' => 'start',
      'no_count' => TRUE,
      'data' => $additional,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function queueProcess(array $data, array $additional = []) {
    foreach ($data as $item_data) {
      $this->queue->createItem([
        'plugin_id' => $this->getPluginId(),
        'op' => 'process',
        'data' => $item_data + $additional,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function queueEnd(array $additional = []) {
    $start = \Drupal::time()->getRequestTime();
    if ($this->usesCleanup()) {
      $this->queue->createItem([
        'plugin_id' => $this->getPluginId(),
        'op' => 'queueCleanup',
        'no_count' => TRUE,
        'data' => $additional,
      ]);
    }
    $this->queue->createItem([
      'plugin_id' => $this->getPluginId(),
      'op' => 'end',
      'no_count' => TRUE,
      'data' => $additional,
    ]);
  }

  /**
   * Fetch the data.
   */
  protected function fetchData($additional = []) {
    try {
      $data = $this->getData();
      if (!empty($data) || $this->shouldRunOnEmpty()) {
        $this->queueStart($additional);
        $this->queueProcess($data, $additional);
        $fetcher = $this->getFetcher();
        if ($fetcher instanceof SyncFetcherPagedInterface) {
          $this->queueFetchPage($data, $additional);
        }
        else {
          $this->queueEnd($additional);
        }
      }
    }
    catch (\Exception $e) {
      $this->log(LogLevel::ERROR, '[Sync Data: %plugin_label] ERROR: %entity_type: @message.', $this->getContext() + [
        '@message' => $e->getMessage(),
      ]);
      if (!empty($additional['_sync_as_batch'])) {
        drupal_set_message($e->getMessage(), 'error');
      }
    }
  }

  /**
   * Get the data.
   *
   * Does not support paging. See fetchData for paging support.
   *
   * @return array
   *   An array of data.
   */
  public function getData() {
    $this->log(LogLevel::INFO, '[Sync Data: %plugin_label] LOAD DATA: %entity_type.', $this->getContext());
    $data = $this->getFetcher()->fetch();
    if (!empty($data)) {
      $data = $this->getParser()->parse($data);
      $this->alterData($data);
      $data = $this->filter($data);
    }
    return $data;
  }

  /**
   * Process all incoming data.
   *
   * Be careful as if the fetcher turns a large number of results this can
   * time out.
   */
  public function getProcessedData($additional = []) {
    $data = $this->getData();
    $results = [];
    foreach ($data as $item) {
      $results[] = $this->process($item + $additional);
    }
    return $results;
  }

  /**
   * Add a pager batch item.
   */
  protected function queueFetchPage($data, $additional = []) {
    $this->queue->createItem([
      'plugin_id' => $this->getPluginId(),
      'op' => 'fetchPage',
      'no_count' => TRUE,
      'data' => [
        'data' => $data,
        'additional' => $additional,
      ],
    ]);
  }

  /**
   * Log a message if called during drush operations.
   */
  protected function drushLog($string, array $args = [], $type = 'info') {
    if (PHP_SAPI === 'cli' && function_exists('drush_print')) {
      $red = "\033[31;40m\033[1m%s\033[0m";
      $yellow = "\033[1;33;40m\033[1m%s\033[0m";
      $green = "\033[1;32;40m\033[1m%s\033[0m";
      switch ($type) {
        case 'error':
          $color = $red;
          break;

        case 'warning':
          $color = $yellow;
          break;

        case 'success':
          $color = $green;
          break;

        default:
          $color = "%s";
          break;
      }
      drush_print(strip_tags(sprintf($color, dt($string, $args))));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPage(array $batch_data) {
    try {
      $page = $this->getPageCount();
      $this->incrementPageCount();
      $previous_data = $batch_data['data'];
      $additional = $batch_data['additional'];
      $fetcher = $this->getFetcher();
      $parser = $this->getParser();
      $this->drushLog('Fetching... [Page: @page | Success: @success | Skipped: @skip | Failed: @fail]', [
        '@page' => $page,
        '@success' => $this->getProcessCount('success'),
        '@skip' => $this->getProcessCount('skip'),
        '@fail' => $this->getProcessCount('fail'),
      ], 'success');
      $new_data = $fetcher->fetchPage($previous_data, $page);
      $new_data = $parser->parse($new_data);
      // There are times when filtering may remove all the results. Because of
      // this, we want to use the pre-filtered data to determine if we should
      // continue filtering.
      $new_data_before_filtering = $new_data;
      $this->alterData($new_data);
      $new_data = $this->filter($new_data);
      $context = $this->getContext() + [
        '%page' => $page,
        '%new_data_count' => count($new_data),
      ];
      if (!empty($new_data_before_filtering)) {
        // We may have more data to fetch, so run again.
        $this->log(LogLevel::INFO, '[Sync Fetch: %plugin_label] PAGE %page: %entity_type with %new_data_count records added for processing.', $context);
        $this->queueProcess($new_data, $additional);
        $this->queueFetchPage($new_data, $additional);
      }
      else {
        // We have all the data we can get.
        $this->log(LogLevel::INFO, '[Sync Fetch: %plugin_label] PAGE FINISH: %entity_type.', $context);
        $this->resetPageCount();
        $this->queueEnd($additional);
      }
      if (!empty($additional['_sync_as_batch'])) {
        $this->buildBatch();
      }
    }
    catch (\Exception $e) {
      $this->log(LogLevel::ERROR, '[Sync Data: %plugin_label] ERROR: %entity_type: @message.', $this->getContext() + [
        '@message' => $e->getMessage(),
      ]);
      drupal_set_message($e->getMessage(), 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function start(array $data) {
    $this->resetPageCount()
      ->resetProcessCount('success')
      ->resetProcessCount('skip')
      ->resetProcessCount('fail');
    \Drupal::service('plugin.manager.sync_resource')->setLastRunStart($this->pluginDefinition, $this->getStartTime());
  }

  /**
   * {@inheritdoc}
   */
  public function end(array $data) {
    $context = $this->getContext() + [
      '%success' => $this->getProcessCount('success'),
      '%skip' => $this->getProcessCount('skip'),
      '%fail' => $this->getProcessCount('fail'),
    ];
    drupal_set_message(t('The data import has completed. %success items were successful, %skip items were skipped, and %fail items failed.', $context));
    $this->resetPageCount()
      ->resetProcessCount('success')
      ->resetProcessCount('skip')
      ->resetProcessCount('fail');
    $this->log(LogLevel::NOTICE, '[Sync Finish: %plugin_label] Success: %success, Skip: %skip, Fail: %fail', $context);
    \Drupal::service('plugin.manager.sync_resource')->setLastRunEnd($this->pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public function resetLastRun() {
    \Drupal::service('plugin.manager.sync_resource')->resetLastRun($this->pluginDefinition);
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
  public function buildBatch($item_callback = 'runBatchProxy', $title = 'Importing Data') {
    $item_count = $this->queue->numberOfItems();
    if ($item_count) {
      $class_name = get_class($this);
      $batch = [
        'title' => t('Batch: %title', ['%title' => $title]),
        'operations' => [],
        'init_message' => t('Commencing'),
        'progress_message' => t('Please do not close this window until import is complete. Task @current out of @total.'),
        'error_message' => t('An error occurred during processing'),
      ];
      for ($i = 0; $i < $item_count; $i++) {
        $batch['operations'][] = [
          [$class_name, $item_callback],
          [$this->pluginId],
        ];
      }
      batch_set($batch);
    }
  }

  /**
   * Finishes an "execute tasks" batch.
   *
   * @param string $plugin_id
   *   The plugin id.
   * @param array $context
   *   The batch context.
   */
  public static function runBatchProxy($plugin_id, array &$context) {
    \Drupal::service('plugin.manager.sync_resource')->createInstance($plugin_id)->runBatch($context);
  }

  /**
   * Runs a batch callback.
   *
   * @param array $context
   *   The batch context.
   */
  public function runBatch(array &$context) {
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $queue_worker */
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('sync');
    $item = $this->queue->claimItem();
    if ($item) {
      try {
        $queue_worker->processItem($item->data);
        $this->queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $this->incrementProcessCount('success');
        }
      }
      catch (SyncSkipException $e) {
        drupal_set_message($e->getMessage(), 'warning');
        $this->queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $this->incrementProcessCount('skip');
        }
      }
      catch (SyncFailException $e) {
        drupal_set_message($e->getMessage(), 'error');
        $this->queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $this->incrementProcessCount('fail');
        }
      }
      catch (\Exception $e) {
        drupal_set_message($e->getMessage(), 'error');
        $this->queue->deleteItem($item);
        watchdog_exception('sync', $e);
        if (empty($item->data['no_count'])) {
          $this->incrementProcessCount('fail');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function filter(array $data) {
    return $data;
  }

  /**
   * {@inheritdoc}
   *
   * @return mixed
   *   Will return FALSE if entity type and bundle was not specified. Will retur
   *   NULL if entity could not be loaded. Will return entity if found/created.
   */
  public function loadEntity(array $data) {
    $entity = FALSE;
    $entity_type = $this->getEntityType();
    $bundle = $this->getBundle($data);
    if ($entity_type && $bundle) {
      $entity = $this->syncEntityProvider->getOrNew($this->id($data), $entity_type, $bundle, [], $this->getGroup($data));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function process(array $data) {
    $context = $this->getContext($data);
    try {
      $this->prepareItem($data);
      // Redo context as things may have changed in prepareItem.
      $context = $this->getContext($data);
      $id = $this->id($data);
      $context['%id'] = $id;
      $entity = $this->loadEntity($data);
      if ($entity instanceof EntityInterface) {
        if ($this->syncAccess($entity)) {
          $this->processItem($entity, $data);
          $success = $this->save($entity, $data);
          $context['%entity_id'] = $entity->id();
          $this->drushLog('Processing: %id [%entity_type:%bundle:%entity_id]', $context);
          switch ($success) {
            case SAVED_NEW:
              $this->log(LogLevel::INFO, '[Sync Item: %plugin_label] NEW: %id for %entity_type:%bundle', $context);
              break;

            case SAVED_UPDATED:
              $this->log(LogLevel::INFO, '[Sync Item: %plugin_label] UPDATED: %id for %entity_type:%bundle', $context);
              break;

            default:
              $this->log(LogLevel::INFO, '[Sync Item: %plugin_label] SUCCESS: %id for %entity_type:%bundle', $context);
              break;
          }
          return $entity;
        }
        else {
          // We save the entity to make sure it is not queued for cleanup.
          if (!$entity->isNew()) {
            $entity->save();
          }
          throw new SyncSkipException('Entity could not be loaded or sync access was halted.');
        }
      }
      else {
        if ($entity === FALSE) {
          // Entity type/bundle not specified. We want to skip silently without
          // errors.
        }
        else {
          throw new \Exception('Entity could not be loaded.');
        }
      }
    }
    catch (SyncSkipException $e) {
      $context['%error'] = $e->getMessage();
      $this->drushLog('Skip: %id [%entity_type:%bundle] %error', $context, 'warning');
      $this->log(LogLevel::INFO, '[Sync Item Skip: %plugin_label] SKIP: %id for %entity_type:%bundle. Error: %error', $context);
      // We need to make sure we have updated this record when skipping.
      if ($this->usesCleanup() && !empty($entity->id())) {
        $this->syncStorage->save($entity->__sync_id, $entity, FALSE, $entity->__sync_group);
      }
      if ($data['_sync_as_batch']) {
        // Send up to runBatch.
        throw new SyncSkipException($e->getMessage());
      }
    }
    catch (SyncFailException $e) {
      $context['%error'] = $e->getMessage();
      $this->drushLog('Fail: %id [%entity_type:%bundle] %error', $context, 'error');
      $this->log(LogLevel::INFO, '[Sync Item Fail: %plugin_label] FAIL: %id for %entity_type:%bundle. Error: %error', $context);
      // We need to make sure we have updated this record when skipping.
      if ($this->usesCleanup() && !empty($entity->id())) {
        $this->syncStorage->save($entity->__sync_id, $entity, FALSE, $entity->__sync_group);
      }
      if ($data['_sync_as_batch']) {
        // Send up to runBatch.
        throw new SyncFailException($e->getMessage());
      }
    }
    catch (\Exception $e) {
      $context['%error'] = $e->getMessage();
      $this->drushLog('Fail: %id [%entity_type:%bundle] %error', $context, 'error');
      $this->log(LogLevel::ERROR, '[Sync Item Fail: %plugin_label] FAIL: %id for %entity_type:%bundle with data %data. Error: %error', $context);
      if ($data['_sync_as_batch']) {
        throw new SyncFailException($e->getMessage());
      }
    }
  }

  /**
   * Prepare an item for processing.
   *
   * Adding/removing/altering data on a per-item level can be done here.
   *
   * @param array $data
   *   The data provided from the Unionware API for a single item.
   */
  protected function prepareItem(array &$data) {}

  /**
   * Extending classes should provide the item process method.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to process.
   * @param array $data
   *   The data provided from the Unionware API for a single item.
   */
  protected function processItem(EntityInterface $entity, array $data) {}

  /**
   * Save the entity and store record in sync table.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to process.
   * @param array $data
   *   The data provided from the Unionware API for a single item.
   *
   * @return bool
   *   A bool representing success.
   */
  protected function save(EntityInterface $entity, array $data) {
    return $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function syncAccess(EntityInterface $entity) {
    return empty($entity->syncIsLocked);
  }

  /**
   * {@inheritdoc}
   */
  public function queueCleanup(array $data) {
    $context = $this->getContext($data);
    try {
      $this->log(LogLevel::INFO, '[Sync Cleanup: %plugin_label] CLEANUP: %entity_type.', $context);
      $query = $this->syncStorage->getDataQuery($this->getGroup($data));
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
          $this->buildBatch();
        }
      }
    }
    catch (\Exception $e) {
      $context['%error'] = $e->getMessage();
      $this->log(LogLevel::ERROR, '[Sync Cleanup: %plugin_label] FAIL: %entity_type:%bundle with data %data. Error: %error', $context);
      if ($data['_sync_as_batch']) {
        throw new \Exception($e->getMessage());
      }
    }
  }

  /**
   * Allow altering of cleanup query.
   */
  protected function cleanupQueryAlter($query, array $data) {
    $query->condition('sync_data.changed', $this->getStartTime(), '<');
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
      $this->log(LogLevel::INFO, '[Sync Clean Skip: %plugin_label] SKIP: %id for %entity_type:%bundle', $context);
      if ($data['_sync_as_batch']) {
        // Send up to runBatch.
        throw new SfHaltException($e->getMessage());
      }
    }
    catch (\Exception $e) {
      $context['%error'] = $e->getMessage();
      $this->log(LogLevel::ERROR, '[Sync Clean: %plugin_label] FAIL: %id for %entity_type:%bundle with data %data. Error: %error', $context);
      if ($data['_sync_as_batch']) {
        throw new \Exception($e->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cleanExecute(EntityInterface $entity, $sync, $context) {
    $status = $entity->delete();
    if ($status) {
      // Entity cleanup is handled in hook_entity_delete().
      $this->log(LogLevel::INFO, '[Sync Clean: %plugin_label] DELETE: %entity_id for %entity_type:%bundle', $context);
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
  public function alterData(array &$data) {
    try {
      $this->alterItems($data);
      foreach ($data as &$item) {
        $this->alterItem($item);
      }
    }
    catch (\Exception $e) {
      $context = $this->getContext($data) + [
        '%message' => $e->getMessage(),
      ];
      $this->log(LogLevel::ERROR, '[Sync Alter Data: %plugin_label] FAIL: %entity_type:%bundle with data %data. Error: %error', $context);
    }
    foreach ($data as &$item) {
      try {
        $this->alterItem($item);
      }
      catch (\Exception $e) {
        $context = $this->getContext($data) + [
          '%message' => $e->getMessage(),
        ];
        $this->log(LogLevel::ERROR, '[Sync Alter Data: %plugin_label] FAIL ITEM: %entity_type:%bundle with data %data. Error: %error', $context);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterItems(array &$data) {
  }

  /**
   * {@inheritdoc}
   */
  public function alterItem(&$data) {
  }

  /**
   * {@inheritdoc}
   */
  public function debug() {
    $data = $this->getData();
    if (!empty($data) && count($data) > $this->maxDebug) {
      $data = array_slice($data, 0, $this->maxDebug);
    }
    if (!empty($data)) {
      foreach ($data as &$item) {
        try {
          $this->prepareItem($item);
        }
        catch (SyncSkipException $e) {
          drupal_set_message($e->getMessage(), 'warning');
        }
        catch (SyncFailException $e) {
          drupal_set_message($e->getMessage(), 'warning');
        }
        catch (\Exception $e) {
          drupal_set_message($e->getMessage(), 'error');
        }
      }
    }
    if (\Drupal::service('module_handler')->moduleExists('kint')) {
      ksm($data);
    }
    else {
      print '<pre>' . print_r($data, FALSE) . '</pre>';
      die;
    }
  }

  /**
   * Log event.
   *
   * Runtime errors that do not require immediate action but should typically
   * be logged and monitored.
   *
   * @param mixed $level
   *   The log level.
   * @param string $message
   *   The log message.
   * @param array $context
   *   The log context.
   */
  protected function log($level, $message, array $context = []) {
    if ($level !== LogLevel::INFO || $this->verboseLog()) {
      $this->logger->log($level, $message, $context);
    }
  }

  /**
   * Should verbose logging be used.
   *
   * @return bool
   *   TRUE if verbose logging should be used.
   */
  protected function verboseLog() {
    if (!isset($this->verboseLog)) {
      $this->verboseLog = \Drupal::config('devel.settings')->get('log_verbose');
    }
    return $this->verboseLog;
  }

  /**
   * {@inheritdoc}
   */
  protected function getContext(array $data = []) {
    $context = [
      '%plugin_id' => $this->getPluginId(),
      '%plugin_label' => $this->label(),
      '%entity_type' => $this->getEntityType(),
      '%id' => 'na',
      '%bundle' => 'na',
    ];
    if (!empty($data)) {
      $context = [
        '%bundle' => $this->getBundle($data),
        '%data' => print_r($data, TRUE),
      ] + $context;
    }
    return $context;
  }

  /**
   * Get the temp storage service.
   */
  // protected function getTempStore() {
  //   return \Drupal::service('user.shared_tempstore')->get('sync');
  // }

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
   * Get the start time.
   */
  protected function getStartTime() {
    return $this->state->get($this->getStateKey() . '.start');
  }

  /**
   * Set the start time.
   */
  protected function setStartTime() {
    $start = \Drupal::time()->getRequestTime();
    $this->state->set($this->getStateKey() . '.start', $start);
    return $this;
  }

  /**
   * Set the page count.
   */
  protected function getPageCount() {
    $count = $this->state->get($this->getStateKey() . '.page');
    return $count ? $count : 1;
  }

  /**
   * Increment the page count.
   */
  protected function incrementPageCount() {
    $count = $this->getPageCount();
    $this->state->set($this->getStateKey() . '.page', $count + 1);
    return $this;
  }

  /**
   * Reset the page count.
   */
  protected function resetPageCount() {
    $this->state->delete($this->getStateKey() . '.page');
    return $this;
  }

  /**
   * Set the process count.
   */
  protected function getProcessCount($type = 'success') {
    $count = $this->state->get($this->getStateKey() . '.process.' . $type);
    return $count ? $count : 0;
  }

  /**
   * Increment the process count.
   */
  protected function incrementProcessCount($type = 'success') {
    $count = $this->getProcessCount($type);
    $this->state->set($this->getStateKey() . '.process.' . $type, $count + 1);
    return $this;
  }

  /**
   * Reset the process count.
   */
  protected function resetProcessCount($type = 'success') {
    $this->state->delete($this->getStateKey() . '.process.' . $type);
    return $this;
  }

}
