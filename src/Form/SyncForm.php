<?php

namespace Drupal\sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sync\Plugin\SyncResourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityManagerInterface;

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
   *   The entity manager.
   * @param \Drupal\sync\Plugin\SyncResourceManager $sync_resource_manager
   *   The resource manager.
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

    $definitions = $this->syncResourceManager->getDefinitions();
    $form['table'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Name'),
        'cleanup' => $this->t('Cleanup'),
        'times' => $this->t('Times'),
        'next' => $this->t('Next Cron Run'),
        'last' => $this->t('Last Run'),
        'actions' => $this->t('Sync Data'),
      ],
    ];
    foreach ($definitions as $definition) {
      $entity_definition = $this->entityManager->getDefinition($definition['entity_type']);
      $bundle_definitions = $this->entityManager->getBundleInfo($definition['entity_type']);
      $enabled = !empty($definition['status']);
      $row = [];
      $row['label']['#markup'] = $this->t('<strong>@label</strong><br><small>Entity: @entity<br>Type: @bundle</small>', [
        '@label' => $definition['label'],
        '@entity' => $entity_definition->getLabel(),
        '@bundle' => isset($bundle_definitions[$definition['bundle']]) ? $bundle_definitions[$definition['bundle']]['label'] : 'None Specified',
      ]);
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
        $row['next']['#markup'] = '<small>' . ($enabled ? $date_formatter->format($next) : '-') . '</small>';
      }
      $last_run = $this->syncResourceManager->getLastRun($definition);
      $row['last']['#markup'] = '<small>' . ($last_run ? $date_formatter->format($last_run) : '-') . '</small>';
      if ($enabled) {
        $row['actions'] = ['#type' => 'actions', '#attributes' => ['style' => 'white-space: nowrap;']];
        $row['actions']['run'] = [
          '#type' => 'submit',
          '#name' => 'action_' . $definition['id'],
          '#button_type' => 'primary',
          '#value' => (string) $this->t('Sync', ['@label' => $definition['label']]),
          '#plugin_id' => $definition['id'],
          '#submit' => ['::sync'],
        ];
      }
      else {
        $row['next']['#markup'] = '<small>' . $this->t('Never') . '</small>';
        $row['run']['#markup'] = '<small>' . $this->t('Disabled') . '</small>';
      }
      if (\Drupal::currentUser()->hasPermission('debug sf')) {
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
    $plugin = $this->syncResourceManager->getDefinition($plugin_id);
    $this->syncResourceManager->createInstance($plugin_id)->runAsBatch();
    $this->syncResourceManager->setLastRun($plugin);
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
