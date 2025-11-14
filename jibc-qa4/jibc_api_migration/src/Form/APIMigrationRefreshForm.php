<?php

namespace Drupal\jibc_api_migration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\migrate\MigrateMessage;
use Drupal\jibc_api_migration\JIBCMigrateExecutable;

class APIMigrationRefreshForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'jibc_api_migration_refresh_courses';
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'jibc_api_migration.settings',
    ];
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $frequency = ($this->config('jibc_api_migration.settings')->get('jibc_api_migration_refresh_frequency'))/3600;
    
    // Check current status
    $db = \Drupal::database();
    $stats = [
      'courses' => $db->query("SELECT COUNT(*) FROM {node} WHERE type = 'course'")->fetchField(),
      'offerings' => $db->query("SELECT COUNT(*) FROM {paragraphs_item} WHERE type = 'course_offering'")->fetchField(),
      'attached' => $db->query("SELECT COUNT(DISTINCT entity_id) FROM {node__field_course_offerings}")->fetchField(),
      'orphaned' => $db->query("
        SELECT COUNT(*) FROM {paragraphs_item} p 
        WHERE p.type = 'course_offering' 
        AND p.id NOT IN (SELECT field_course_offerings_target_id FROM {node__field_course_offerings})
      ")->fetchField(),
    ];
    
    $form['status'] = [
      '#type' => 'details',
      '#title' => t('Current Status'),
      '#open' => TRUE,
      '#description' => t('
        <ul>
          <li>Total Courses: <strong>@courses</strong></li>
          <li>Total Course Offerings: <strong>@offerings</strong></li>
          <li>Courses with Offerings Attached: <strong>@attached</strong></li>
          <li>Orphaned Offerings: <strong>@orphaned</strong></li>
        </ul>
      ', [
        '@courses' => $stats['courses'],
        '@offerings' => $stats['offerings'],
        '@attached' => $stats['attached'],
        '@orphaned' => $stats['orphaned'],
      ]),
    ];
    
    $form['jibc_api_migration_refresh_all'] = [
      '#type' => 'details',
      '#title' => t('Refresh Course Synchronization'),
      '#description' => t('The course synchronization operation is done automatically every ' . $frequency . ' hours in the background but you can run it manually here.<br /><br />'),
      '#open' => TRUE,
    ];
    
    $form['jibc_api_migration_refresh_all']['jibc_api_migration_refresh_action'] = [
      '#type' => 'submit',
      '#value' => t('Refresh All Courses'),
      '#submit' => ['::refreshCourses'],
    ];
    
    // Add attach orphaned offerings button if needed
    if ($stats['orphaned'] > 0) {
      $form['jibc_api_migration_refresh_all']['jibc_api_migration_attach_action'] = [
        '#type' => 'submit',
        '#value' => t('Attach @count Orphaned Offerings', ['@count' => $stats['orphaned']]),
        '#submit' => ['::attachOrphaned'],
        '#attributes' => [
          'class' => ['button--primary'],
        ],
      ];
    }
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This is now handled by specific submit handlers
  }
  
  /**
   * Submit handler for refresh courses
   */
  public function refreshCourses(array &$form, FormStateInterface $form_state) {
    $tempstore = \Drupal::service('tempstore.private')->get('jibc_api_migration');
    $tempstore->set('trigger', 'user');
    
    $service = \Drupal::service('jibc_api_migration.refresh');
    $service->refreshAllCourses();
  }
  
  /**
   * Submit handler for attach orphaned offerings
   */
  public function attachOrphaned(array &$form, FormStateInterface $form_state) {
    $service = \Drupal::service('jibc_api_migration.refresh');
    $service->attachOrphanedOfferings();
    
    // Rebuild the form to update the counts
    $form_state->setRebuild();
  }

  public function rollBackCourses(){
    $migration_id = 'new_courses';
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
    $executable = new JIBCMigrateExecutable(
      $migration, 
      new MigrateMessage()
    );
    return $executable->rollbackMissingItems();
  }
}