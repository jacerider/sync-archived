<?php

namespace Drupal\sync\Plugin\SyncFetcher;

use Drupal\sync\Plugin\SyncFetcherBase;

/**
 * Plugin implementation of the 'soap' sync resource.
 *
 * @SyncFetcher(
 *   id = "soap",
 *   label = @Translation("HTTP"),
 * )
 */
class Soap extends SyncFetcherBase {

  /**
   * Determines if batch featching is supported.
   *
   * @var bool
   */
  protected $supportsPaging = TRUE;

  /**
   * The bookmark key value.
   *
   * @var string
   */
  protected $bookmarkKey = NULL;

  /**
   * {@inheritdoc}
   */
  protected function defaultSettings() {
    return [
      'url' => '',
      'options' => [],
      'params' => [],
      // Example filters: [['Field' => 'Description', 'Criteria' => '*PIPE*']].
      'filters' => [],
      'bookmarkKey' => '',
      'size' => 100,
      'resource_name' => NULL,
      'login' => NULL,
      'password' => NULL,
    ];
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
    $params = $this->configuration['params'];
    $params += [
      'filter' => $this->configuration['filters'],
      'filter' => [
        'Field' => 'Show_on_Web',
        'Criteria' => FALSE,
      ],
      'setSize' => $this->configuration['size'],
    ];
    if (!empty($this->bookmarkKey)) {
      $params += [
        'bookmarkKey' => $this->bookmarkKey,
      ];
    }
    return $params;
  }

  /**
   * Called when paging is enabled.
   *
   * @return array|null
   *   Should return results of paged fetch. If null or empty array, paging will
   *   end.
   */
  public function fetchPage($previous_data, $page) {
    if ($page == 4) {
      return [];
    }
    if (!empty($previous_data) && !empty($this->configuration['bookmarkKey'])) {
      $item = end($previous_data);
      if (!empty($item[$this->configuration['bookmarkKey']])) {
        $this->bookmarkKey = $item[$this->configuration['bookmarkKey']];
      }
      return $this->fetch();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function fetch() {
    $client = new \SoapClient($this->configuration['url'], $this->getOptions());
    if (!empty($this->configuration['resource_name'])) {
      $data = $client->__soapCall($this->configuration['resource_name'], [
        'parameters' => $this->getParams(),
      ]);
      if (is_object($data) && isset($data->{$this->configuration['resource_name'] . 'Result'})) {
        $data = $data->{$this->configuration['resource_name'] . 'Result'};
      }
      else {
        $data = [];
      }
    }
    else {
      $data = $client->ReadMultiple($this->getParams());
      if (is_object($data) && isset($data->{'ReadMultiple_Result'}->{'ItemList'})) {
        $data = $data->{'ReadMultiple_Result'}->{'ItemList'};
      }
      else {
        $data = [];
      }
    }
    return $data;
  }

}
