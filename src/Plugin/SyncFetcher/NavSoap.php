<?php

namespace Drupal\sync\Plugin\SyncFetcher;

use Drupal\sync\Plugin\SyncFetcherPagedInterface;

/**
 * Plugin implementation of the 'nav_soap' sync resource.
 *
 * @SyncFetcher(
 *   id = "nav_soap",
 *   label = @Translation("Nav: Soap"),
 * )
 */
class NavSoap extends Soap implements SyncFetcherPagedInterface {

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
    return parent::defaultSettings() + [
      // Example filters: [['Field' => 'Description', 'Criteria' => '*PIPE*']].
      'filters' => [],
      'bookmark_key' => 'Key',
      'size' => 200,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return $this->configuration['filters'];
  }

  /**
   * {@inheritdoc}
   */
  public function setFilters($filters) {
    $this->configuration['filters'] = $filters;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function resetFilters() {
    return $this->setFilters([]);
  }

  /**
   * {@inheritdoc}
   */
  public function addFilter($field, $criteria) {
    $this->configuration['filters'][] = [
      'Field' => $field,
      'Criteria' => $criteria,
    ];
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSize() {
    return $this->configuration['size'];
  }

  /**
   * {@inheritdoc}
   */
  public function setSize($size) {
    $this->configuration['size'] = $size;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    return $this->configuration['url'] . '/' . $this->getResourceName();
  }

  /**
   * {@inheritdoc}
   */
  public function getParams() {
    $params = parent::getParams();
    $params += [
      'filter' => $this->getFilters(),
      'setSize' => $this->getSize(),
    ];
    if (!empty($this->bookmarkKey)) {
      $params += [
        'bookmarkKey' => $this->bookmarkKey,
      ];
    }
    return $params;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPage($previous_data, $page) {
    if (!empty($previous_data) && !empty($this->configuration['bookmark_key'])) {
      $item = end($previous_data);
      if (!empty($item[$this->configuration['bookmark_key']])) {
        $this->bookmarkKey = $item[$this->configuration['bookmark_key']];
      }
      return $this->fetch();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function fetch() {
    $data = [];
    $client = new \SoapClient($this->getUrl(), $this->getOptions());
    $results = $client->ReadMultiple($this->getParams());
    if (is_object($results) && isset($results->{'ReadMultiple_Result'}->{$this->getResourceName()})) {
      $data = $results->{'ReadMultiple_Result'}->{$this->getResourceName()};
      if (!is_array($data)) {
        $data = [$data];
      }
    }
    return $data;
  }

}
