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
use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sync\SyncStorageInterface;
use Drupal\Core\State\StateInterface;

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
  public function run(array $additional = []) {
    $this->logger->notice('[Sync Queue: %plugin_label] START: %entity_type.', $this->getContext());
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

  /**
   * Get the data.
   *
   * Does not support paging. See fetchData for paging support.
   *
   * @return array
   *   An array of data.
   */
  public function getData() {
    try {
      $this->logger->notice('[Sync Data: %plugin_label] LOAD DATA: %entity_type.', $this->getContext());
      $data = $this->getFetcher()->fetch();
      $data = $this->getParser()->parse($data);
      $data = $this->filter($data);
      $this->alterData($data);
      foreach ($data as &$item) {
        $this->alterItem($item);
      }
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
   * {@inheritdoc}
   */
  public function fetchPage(array $batch_data) {
    $page = $this->getPageCount();
    $this->incrementPageCount();
    $previous_data = $batch_data['data'];
    $additional = $batch_data['additional'];
    $fetcher = $this->getFetcher();
    $parser = $this->getParser();
    if (PHP_SAPI === 'cli' && function_exists('drush_print')) {
      if ($page > 1) {
        $red = "\033[31;40m\033[1m%s\033[0m";
        $yellow = "\033[1;33;40m\033[1m%s\033[0m";
        $green = "\033[1;32;40m\033[1m%s\033[0m";
        drush_log(sprintf($green, dt('Fetching... [Page: @page | Success: @success | Skipped: @skip | Failed: @fail]', [
          '@page' => $page,
          '@success' => $this->getProcessCount('success'),
          '@skip' => $this->getProcessCount('skip'),
          '@fail' => $this->getProcessCount('fail'),
        ])), 'ok');
      }
    }
    $new_data = $fetcher->fetchPage($previous_data, $page);
    $new_data = $parser->parse($new_data);
    $new_data = $this->filter($new_data);
    $context = $this->getContext() + [
      '%page' => $page,
      '%new_data_count' => count($new_data),
    ];
    if (!empty($new_data)) {
      // We may have more data to fetch, so run again.
      $this->logger->notice('[Sync Fetch: %plugin_label] PAGE %page: %entity_type with %new_data_count records added for processing.', $context);
      $this->queueProcess($new_data, $additional);
      $this->queueFetchPage($new_data, $additional);
    }
    else {
      // We have all the data we can get.
      $this->logger->notice('[Sync Fetch: %plugin_label] PAGE FINISH: %entity_type.', $context);
      $this->resetPageCount();
      $this->queueEnd($additional);
    }
    if (!empty($additional['_sync_as_batch'])) {
      $this->buildBatch();
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
    drupal_set_message(t('The data import has completed. %success items were successful, %skip items were skipped, and %fail items failed.', [
      '%success' => $this->getProcessCount('success'),
      '%skip' => $this->getProcessCount('skip'),
      '%fail' => $this->getProcessCount('fail'),
    ]));
    $this->resetPageCount()
      ->resetProcessCount('success')
      ->resetProcessCount('skip')
      ->resetProcessCount('fail');
    $this->logger->notice('[Sync Queue: %plugin_label] FINISH: %entity_type.', $this->getContext());
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
      catch (SyncHaltException $e) {
        drupal_set_message($e->getMessage(), 'error');
        $this->queue->deleteItem($item);
        if (empty($item->data['no_count'])) {
          $this->incrementProcessCount('skip');
        }
      }
      catch (SuspendQueueException $e) {
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
      if ($entity) {
        if ($this->syncAccess($entity)) {
          if (PHP_SAPI === 'cli' && function_exists('drush_print')) {
            drush_print(dt('Processing: @id', ['@id' => $id]));
          }
          $this->processItem($entity, $data);
          $success = $this->save($entity, $data);
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
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to process.
   * @param array $data
   *   The data provided from the Unionware API for a single item.
   */
  abstract protected function processItem(EntityInterface $entity, array $data);

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
      $this->logger->notice('[Sync Cleanup: %plugin_label] CLEANUP: %entity_type.', $context);
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
          $this->buildBatch();
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
  public function cleanExecute(EntityInterface $entity, $sync, $context) {
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
  public function alterData(array &$data) {
  }

  /**
   * {@inheritdoc}
   */
  public function alterItem(array &$data) {
  }

  /**
   * {@inheritdoc}
   */
  public function debug() {
    try {
      $data = $this->getData();
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
