<?php

namespace Drupal\sync\Plugin\SyncFetcher;

use Drupal\sync\Plugin\SyncFetcherBase;

/**
 * Plugin implementation of the 'soap' sync resource.
 *
 * @SyncFetcher(
 *   id = "soap",
 *   label = @Translation("Soap"),
 * )
 */
class Soap extends SyncFetcherBase {

  /**
   * {@inheritdoc}
   */
  protected function defaultSettings() {
    return [
      'url' => '',
      'options' => [],
      'params' => [],
      'resource_name' => NULL,
      'login' => NULL,
      'password' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    return $this->configuration['url'];
  }

  /**
   * {@inheritdoc}
   */
  public function setUrl($url) {
    $this->configuration['url'] = $url;
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceName() {
    return $this->configuration['resource_name'];
  }

  /**
   * {@inheritdoc}
   */
  public function setResourceName($resource_name) {
    $this->configuration['resource_name'] = $resource_name;
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
  public function setQueryParameter($key, $value) {
    $this->configuration['query'][$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuth() {
    return !empty($this->configuration['login']) && !empty($this->configuration['password']) ? [
      'login' => $this->configuration['login'],
      'password' => $this->configuration['password'],
    ] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions() {
    $options = $this->configuration['options'];
    $options += $this->getAuth();
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getParams() {
    return $this->configuration['params'];
  }

  /**
   * {@inheritdoc}
   */
  public function fetch() {
    $client = new \SoapClient($this->getUrl(), $this->getOptions());
    $data = [];
    if (!empty($this->getResourceName())) {
      $results = $client->__soapCall($this->getResourceName(), [
        'parameters' => $this->getParams(),
      ]);
      if (is_object($results) && isset($results->{$this->getResourceName() . 'Result'})) {
        $data = $results->{$this->getResourceName() . 'Result'};
      }
    }
    return $data;
  }

}
