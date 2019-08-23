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
  protected $bookmarkKey = '32;GwAAAAJ7/zgAMgA5AC0AMgA5ADEAQw==9;3678536570;';
  // protected $bookmarkKey = '';

  /**
   * {@inheritdoc}
   */
  protected function defaultSettings() {
    return parent::defaultSettings() + [
      // Example filters: [['Field' => 'Description', 'Criteria' => '*PIPE*']].
      'filters' => [],
      'resource_segment' => 'Page',
      'resource_function' => 'ReadMultiple',
      'resource_function_result' => 'ReadMultiple_Result',
      'bookmark_key' => 'Key',
      'size' => 100,
      'page_limit' => 0,
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
  public function getResourceSegment() {
    return $this->configuration['resource_segment'];
  }

  /**
   * {@inheritdoc}
   */
  public function setResourceSegment($resource_segment) {
    $this->configuration['resource_segment'] = $resource_segment;
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceFunction() {
    return $this->configuration['resource_function'];
  }

  /**
   * {@inheritdoc}
   */
  public function setResourceFunction($resource_function) {
    $this->configuration['resource_function'] = $resource_function;
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceFunctionResult() {
    return $this->configuration['resource_function_result'];
  }

  /**
   * {@inheritdoc}
   */
  public function setResourceFunctionResult($resource_function_result) {
    $this->configuration['resource_function_result'] = $resource_function_result;
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
  public function getPageLimit() {
    return $this->configuration['page_limit'];
  }

  /**
   * {@inheritdoc}
   */
  public function setPageLimit($limit) {
    $this->configuration['page_limit'] = $limit;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    return $this->configuration['url'] . '/' . $this->getResourceSegment() . '/' . $this->getResourceName();
  }

  /**
   * {@inheritdoc}
   */
  public function getParams() {
    $this->addParam('filter', $this->getFilters());
    $this->addParam('setSize', $this->getSize());
    if (!empty($this->bookmarkKey)) {
      $this->addParam('bookmarkKey', $this->bookmarkKey);
    }
    return parent::getParams();
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPage($previous_data, $page) {
    if (!empty($this->getPageLimit()) && $page >= $this->getPageLimit()) {
      return [];
    }
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
    $function = $this->getResourceFunction();
    $function_result = $this->getResourceFunctionResult();
    $results = $client->{$function}($this->getParams());
    if ($function_result && is_object($results) && isset($results->{$function_result}->{$this->getResourceName()})) {
      $data = $results->{$function_result}->{$this->getResourceName()};
      if (!is_array($data)) {
        $data = [$data];
      }
    }
    return $data;
  }

}
