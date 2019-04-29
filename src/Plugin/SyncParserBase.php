<?php

namespace Drupal\sync\Plugin;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for Sync Parser plugins.
 */
abstract class SyncParserBase extends PluginBase implements SyncParserInterface {

  /**
   * Parse the data.
   */
  abstract public function parse($data);

}
