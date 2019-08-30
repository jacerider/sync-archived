<?php

namespace Drupal\sync\Plugin\SyncFetcher;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\sync\Plugin\SyncFetcherBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;

/**
 * Plugin implementation of the 'http' sync resource.
 *
 * @SyncFetcher(
 *   id = "http",
 *   label = @Translation("HTTP"),
 * )
 */
class Http extends SyncFetcherBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a SyncFetcher object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The Guzzle HTTP client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultSettings() {
    return [
      'url' => '',
      'query' => [],
      'as_content' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuery() {
    return $this->configuration['query'];
  }

  /**
   * {@inheritdoc}
   */
  public function setQuery($query) {
    $this->configuration['query'] = $query;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryParameter($key) {
    return isset($this->configuration['query'][$key]) ? $this->configuration['query'][$key] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setQueryParameter($key, $value) {
    $this->configuration['query'][$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions() {
    $options = [];
    $options['query'] = $this->getQuery();
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch() {
    $data = $this->httpClient->request('GET', $this->configuration['url'], $this->getOptions())->getBody();
    if ($this->configuration['as_content']) {
      $data = $data->getContents();
    }
    return $data;
  }

}
