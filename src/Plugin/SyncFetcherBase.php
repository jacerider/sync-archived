<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for Sync Fetcher plugins.
 */
abstract class SyncFetcherBase extends PluginBase implements SyncFetcherInterface, ContainerFactoryPluginInterface {

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SyncResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger) {
    $configuration += $this->defaultSettings();
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger->get('sync');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')
    );
  }

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
  protected function getContext() {
    $context = [
      '%plugin_id' => $this->getPluginId(),
      '%plugin_label' => $this->label(),
    ];
    return $context;
  }

  /**
   * Provides default settings.
   */
  protected function defaultSettings() {
    return [];
  }

  /**
   * Called when paging is enabled.
   *
   * @return array
   *   Should return results of paged fetch. If empty array, paging will end.
   */
  public function fetchPage($previous_data, $page) {
    return [];
  }

  /**
   * Build the request.
   */
  abstract public function fetch();

}
