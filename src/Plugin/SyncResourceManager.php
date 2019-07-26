<?php

namespace Drupal\sync\Plugin;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the Sync Resource plugin manager.
 */
class SyncResourceManager extends DefaultPluginManager {

  /**
   * Constructs a new SyncResourceManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/SyncResource', $namespaces, $module_handler, 'Drupal\sync\Plugin\SyncResourceInterface', 'Drupal\sync\Annotation\SyncResource');
    $this->alterInfo('sync_sync_resource_info');
    $this->setCacheBackend($cache_backend, 'sync_sync_resource_plugins');
    $this->defaults = [
      'status' => 1,
      'entity_type' => '',
      'bundle' => '',
      'cron' => '00:00',
      'day' => 'mon,tue,wed,thu,fri',
      'no_ui' => FALSE,
      'cleanup' => FALSE,
      'weight' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    $definitions = parent::getDefinitions();
    uasort($definitions, [get_class($this), 'sort']);
    return $definitions;
  }

  /**
   * Get an instance of all active plugins.
   */
  public function getActive() {
    $plugins = [];
    foreach ($this->getDefinitions() as $definition) {
      if (!empty($definition['status'])) {
        $plugins[$definition['id']] = $this->createInstance($definition['id']);
      }
    }
    return $plugins;
  }

  /**
   * Get an instance of all plugins that have not been processed by cron.
   *
   * We use the plugin cron property to determine at which time of the day
   * each plugin can be run. It will only be allowed to run once per day.
   *
   * @return \Drupal\sf\Plugin\SfPluginInstance[]
   *   An array of Sf plugins.
   */
  public function getForCron() {
    $request_time = \Drupal::time()->getCurrentTime();
    $plugins = [];
    foreach ($this->getDefinitions() as $definition) {
      if (empty($definition['status']) || empty($definition['cron'])) {
        continue;
      }
      $cron_time = $this->getNextCronTime($definition);
      // Cron time returns the next available time, which may be tomorrow. We
      // only want to run times that are on the same day.
      if ($cron_time <= $request_time && date('ymd', $cron_time) == date('ymd', $request_time)) {
        $plugins[$definition['id']] = $this->createInstance($definition['id']);
      }
    }
    return $plugins;
  }

  /**
   * Get the next cron time.
   *
   * @return string
   *   The next cron time.
   */
  public function getNextCronTime(array $definition) {
    $times = $this->getCronTimes($definition);
    $days = $this->getCronDays($definition);
    if ($times && $days) {
      $request_time = \Drupal::time()->getRequestTime();
      $last = $this->getLastRunStart($definition);
      $ran_today = date('ymd', $last) === date('ymd', $request_time);

      // Check to make sure current day is supported.
      if ($next_day = $this->getNextCronDay($definition)) {
        return $next_day;
      }

      foreach ($times as $time) {
        $cron_time = strtotime($time);
        if ($cron_time > $last) {
          return $cron_time;
        }
      }

      if ($ran_today) {
        // Check to make sure current day is supported.
        return $this->getNextCronDay($definition, TRUE);
      }
      return strtotime($times[0]);
    }
    return NULL;
  }

  /**
   * Get the next cron day.
   *
   * @return string|null
   *   If null, the cron can be run on the current day.
   */
  protected function getNextCronDay(array $definition, $force_next = FALSE) {
    $times = $this->getCronTimes($definition);
    $days = $this->getCronDays($definition);
    if ($times && $days) {
      $days_of_week = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

      // Check to make sure current day is supported.
      if (!in_array(strtolower(date('D')), $days) || $force_next) {
        foreach ($days_of_week as $day) {
          if (in_array($day, $days) && strtotime($day . ' this week') > time()) {
            return strtotime($day . ' this week ' . $times[0], strtotime($times[0]));
          }
        }
        // We havn't found a match which means nothing more happens this week.
        foreach ($days_of_week as $day) {
          if (in_array($day, $days)) {
            return strtotime($day . ' next week ' . $times[0], strtotime($times[0]));
          }
        }

      }
    }
    return NULL;
  }

  /**
   * Get all cron times.
   *
   * @return array
   *   All cron times.
   */
  public function getCronTimes(array $definition) {
    return $definition['cron'] ? array_map('trim', explode(',', $definition['cron'])) : FALSE;
  }

  /**
   * Get all cron days.
   *
   * @return array
   *   All cron days.
   */
  public function getCronDays(array $definition) {
    return $definition['cron'] ? array_map('trim', explode(',', $definition['day'])) : FALSE;
  }

  /**
   * Get the state key.
   *
   * @param array $definition
   *   The plugin definition.
   *
   * @return string
   *   The state key.
   */
  protected static function getStateKey(array $definition) {
    return 'sync.cron.' . $definition['id'];
  }

  /**
   * Get last run timestamp.
   *
   * @param array $definition
   *   The plugin definition.
   *
   * @return string
   *   A timestamp.
   */
  public static function getLastRunStart(array $definition) {
    $state = \Drupal::state();
    $key = self::getStateKey($definition);
    return $last = $state->get($key, 0);
  }

  /**
   * Sets last run timestamp.
   *
   * @param array $definition
   *   The plugin definition.
   * @param string $timestamp
   *   The start timestamp.
   *
   * @return string
   *   A timestamp.
   */
  public function setLastRunStart(array $definition, $timestamp = NULL) {
    $state = \Drupal::state();
    $key = self::getStateKey($definition);
    $timestamp = $timestamp ? $timestamp : \Drupal::time()->getCurrentTime();
    $state->set($key, $timestamp);
    return $timestamp;
  }

  /**
   * Get last run timestamp.
   *
   * @param array $definition
   *   The plugin definition.
   *
   * @return string
   *   A timestamp.
   */
  public static function getLastRunEnd(array $definition) {
    $state = \Drupal::state();
    $key = self::getStateKey($definition) . '.end';
    return $last = $state->get($key, 0);
  }

  /**
   * Sets last run timestamp.
   *
   * @param array $definition
   *   The plugin definition.
   * @param string $timestamp
   *   The start timestamp.
   *
   * @return string
   *   A timestamp.
   */
  public function setLastRunEnd(array $definition, $timestamp = NULL) {
    $state = \Drupal::state();
    $key = self::getStateKey($definition) . '.end';
    $timestamp = $timestamp ? $timestamp : \Drupal::time()->getCurrentTime();
    $state->set($key, $timestamp);
    return $timestamp;
  }

  /**
   * Reset last run timestamp.
   *
   * @param array $definition
   *   The plugin definition.
   *
   * @return string
   *   A timestamp.
   */
  public static function resetLastRun(array $definition) {
    $state = \Drupal::state();
    $key = self::getStateKey($definition);
    return $state->delete($key) && $state->delete($key . '.end');
  }

  /**
   * Sorts active blocks by weight; sorts inactive blocks by name.
   */
  public static function sort(array $a, array $b) {
    // Separate enabled from disabled.
    $status = (int) $b['status'] - (int) $a['status'];
    if ($status !== 0) {
      return $status;
    }

    // Separate cron from cronless.
    $cron = !empty($b['cron']) - !empty($a['cron']);
    if ($cron !== 0) {
      return $cron;
    }

    // Sort by weight.
    $weight = $a['weight'] - $b['weight'];
    if ($weight) {
      return $weight;
    }

    // Sort by label.
    return strcmp($a['label'], $b['label']);
  }

}
