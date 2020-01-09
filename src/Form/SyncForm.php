<?php

namespace Drupal\sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sync\Plugin\SyncResourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Url;

/**
 * Class SyncForm.
 */
class SyncForm extends FormBase {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The sync resource manager.
   *
   * @var \Drupal\sync\Plugin\SyncResourceManager
   */
  protected $syncResourceManager;

  /**
   * Constructs a new AuthorizeForm object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   The entity manager.
   * @param \Drupal\sync\Plugin\SyncResourceManager $sync_resource_manager
   The resource manager.
   */
  public function __construct(EntityManagerInterface $entity_manager, SyncResourceManager $sync_resource_manager) {
    $this->entityManager = $entity_manager;
    $this->syncResourceManager = $sync_resource_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('plugin.manager.sync_resource')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sync_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $request_time = \Drupal::time()->getCurrentTime();
    $date_formatter = \Drupal::service('date.formatter');
    $user = $this->currentUser();

    $definitions = $this->syncResourceManager->getActiveDefinitions();
    $form['table'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Name'),
        'cleanup' => $this->t('Cleanup'),
        'times' => $this->t('Times'),
        'next' => $this->t('Next Cron Run'),
        'last' => $this->t('Last Run'),
        'actions' => $this->t('Actions'),
        'log' => $this->t('Log'),
      ],
    ];
    foreach ($definitions as $definition) {
      if (!empty($definition['no_ui'])) {
        continue;
      }
      $context = [
        '@label' => $definition['label'],
        '@id' => $definition['id'],
        '@entity' => '-',
        '@bundle' => '-',
      ];
      if ($definition['entity_type']) {
        $entity_definition = $this->entityManager->getDefinition($definition['entity_type']);
        $bundle_definitions = $this->entityManager->getBundleInfo($definition['entity_type']);
        $context = [
          '@entity' => $entity_definition->getLabel(),
          '@bundle' => isset($bundle_definitions[$definition['bundle']]) ? $bundle_definitions[$definition['bundle']]['label'] : 'None Specified',
        ] + $context;
      }
      $computed = !empty($definition['computed']);
      $row = [];
      $row['label']['#markup'] = $this->t('<strong>@label</strong> <small>(@id)</small><br><small>Entity: @entity<br>Bundle: @bundle</small>', $context);
      $row['clean']['#markup'] = '<small>' . (!empty($definition['cleanup']) ? $this->t('Yes') : $this->t('No')) . '</small>';
      if (($times = $this->syncResourceManager->getCronTimes($definition)) && ($days = $this->syncResourceManager->getCronDays($definition))) {
        $row['times']['#markup'] = '<em>' . implode(', ', array_map(function ($time) {
          return date('g:ia', strtotime($time));
        }, $times)) . '</em><br><small>' . implode(', ', array_map(function ($time) {
          return date('D', strtotime($time));
        }, $days)) . '</small>';
      }
      else {
        $row['times']['#markup'] = '-';
      }
      $next = $this->syncResourceManager->getNextCronTime($definition);
      if (!$next) {
        $row['next']['#markup'] = '-';
      }
      elseif ($next < $request_time) {
        $row['next']['#markup'] = '<small>Next Run</small>';
      }
      else {
        $row['next']['#markup'] = '<small>' . (!$computed ? $date_formatter->format($next) : '-') . '</small>';
      }
      $last_run_start = $this->syncResourceManager->getLastRunStart($definition);
      $last_run_end = $this->syncResourceManager->getLastRunEnd($definition);
      $row['last']['#markup'] = $this->t('<small>Start: %start<br>Finish: %end</small>', [
        '%start' => $last_run_start ? $date_formatter->format($last_run_start) : '-',
        '%end' => $last_run_end ? $date_formatter->format($last_run_end) : '-',
      ]);
      $enable = $user->hasPermission('sync run all');
      if (!$enable && $next) {
        $enable = $user->hasPermission('sync run scheduled');
      }
      if ($computed) {
        $enable = FALSE;
      }
      $row['actions'] = ['#type' => 'actions', '#attributes' => ['style' => 'white-space: nowrap;']];
      $row['actions']['run'] = [
        '#type' => 'submit',
        '#name' => 'action_' . $definition['id'],
        '#button_type' => 'primary',
        '#value' => (string) $this->t('Sync', ['@label' => $definition['label']]),
        '#disabled' => !$enable,
        '#plugin_id' => $definition['id'],
        '#submit' => ['::sync'],
      ];
      if (!empty($definition['reset']) && $user->hasPermission('sync reset')) {
        $row['actions']['reset'] = [
          '#type' => 'submit',
          '#name' => 'reset_' . $definition['id'],
          '#value' => (string) $this->t('Reset', ['@label' => $definition['label']]),
          '#plugin_id' => $definition['id'],
          '#submit' => ['::reset'],
        ];
      }
      $row['log'] = [
        '#type' => 'link',
        '#title' => $this->t('Log'),
        '#url' => Url::fromRoute('sync.log', ['plugin_id' => $definition['id']]),
      ];
      if ($user->hasPermission('sync debug')) {
        $form['table']['#header']['devel'] = $this->t('Debug');
        $row['devel'] = [
          '#type' => 'submit',
          '#name' => 'debug_' . $definition['id'],
          '#value' => (string) $this->t('Debug', ['@label' => $definition['label']]),
          '#plugin_id' => $definition['id'],
          '#submit' => ['::debug'],
        ];
      }
      $form['table'][$definition['id']] = $row;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function sync(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $plugin_id = $trigger['#plugin_id'];
    $this->syncResourceManager->createInstance($plugin_id)->runAsBatch();
  }

  /**
   * {@inheritdoc}
   */
  public function reset(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $plugin_id = $trigger['#plugin_id'];
    $this->syncResourceManager->createInstance($plugin_id)->resetLastRun();
  }

  /**
   * {@inheritdoc}
   */
  public function debug(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $plugin_id = $trigger['#plugin_id'];
    $this->syncResourceManager->createInstance($plugin_id)->debug();
  }

}
