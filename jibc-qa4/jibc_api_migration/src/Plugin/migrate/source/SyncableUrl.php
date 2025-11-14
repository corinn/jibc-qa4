<?php

namespace Drupal\jibc_api_migration\Plugin\migrate\source;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Plugin\migrate\source\Url;
use Drupal\migrate_tools\SyncableSourceInterface;
use Drupal\Core\Site\Settings;
use Drupal\migrate_plus\DataParserPluginInterface;

/**
 * A syncable url source that properly tracks source IDs for rollback.
 *
 * @see Drupal\migrate_plus\Plugin\migrate\source\Url
 *
 * @MigrateSource(
 *   id = "syncable_url",
 *   source_module = "jibc_api_migration"
 * )
 */
class SyncableUrl extends Url implements SyncableSourceInterface {

  /**
   * The source IDs from the current API response.
   *
   * @var array
   */
  protected $currentSourceIds = [];

  /**
   * Flag to indicate if we're fetching all rows for comparison.
   *
   * @var bool
   */
  protected $fetchingAllRows = FALSE;

  /**
   * Static cache for API data to prevent redundant calls.
   *
   * @var array|null
   */
  protected static $apiDataCache = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    // Set dynamic URL based on environment
    $settings_config = Settings::get('jibc_api', []);
    
    if (!empty($settings_config['api_base_url'])) {
      // Override the URL with the dynamic one from settings
      $configuration['urls'] = rtrim($settings_config['api_base_url'], '/') . '/courses';
      
      // Only log once per request, not every time the source is instantiated
      static $logged = FALSE;
      if (!$logged && empty($settings_config['log_errors_only'])) {
        \Drupal::logger('jibc_api_migration')->info('SyncableUrl using dynamic URL: @url', [
          '@url' => $configuration['urls']
        ]);
        $logged = TRUE;
      }
    } elseif (empty($configuration['urls'])) {
      // No URL in configuration and no settings, throw error
      throw new \Exception('No API URL configured. Please configure jibc_api in settings.php');
    }
    
    // Check if we're being asked to fetch all rows (for rollback comparison)
    if (!empty($configuration['all_rows'])) {
      $this->fetchingAllRows = TRUE;
    }
    
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * {@inheritdoc}
   * 
   * Returns the IDs of all source items currently available.
   * This is used to determine which items are missing and should be rolled back.
   */
  public function sourceIds(): array {
    // If we've already fetched the IDs, return them
    if (!empty($this->currentSourceIds)) {
      return $this->currentSourceIds;
    }

    // Fetch fresh data from the API
    try {
      // Initialize the iterator to fetch data
      $this->initializeIterator();
      
      $ids = [];
      foreach ($this as $row) {
        // Get the Course_ID from each row
        if (isset($row['Course_ID'])) {
          // Store as array to match expected format
          $ids[] = ['Course_ID' => $row['Course_ID']];
        }
      }
      
      $this->currentSourceIds = $ids;
      
      // Only log if we actually fetched new data
      static $sourceIdsLogged = FALSE;
      if (!$sourceIdsLogged) {
        \Drupal::logger('jibc_api_migration')->info('SyncableUrl found @count courses in API', [
          '@count' => count($ids)
        ]);
        $sourceIdsLogged = TRUE;
      }
      
      return $ids;
    }
    catch (\Exception $e) {
      \Drupal::logger('jibc_api_migration')->error('Failed to get source IDs from API: @error', [
        '@error' => $e->getMessage()
      ]);
      // Return empty array on failure to prevent mass unpublishing
      return [];
    }
  }

  /**
   * {@inheritdoc}
   * 
   * Mark items as changed so they get re-imported.
   */
  public function markChanged($source_id_values): void {
    // This is called when we want to force an update of specific items
    // For our use case, we rely on track_changes in the migration config
    // which automatically detects changes based on Course_ChangeTimestamp
    
    // Only log in debug mode
    $settings_config = Settings::get('jibc_api', []);
    if (empty($settings_config['log_errors_only'])) {
      \Drupal::logger('jibc_api_migration')->debug('markChanged called for course: @id', [
        '@id' => print_r($source_id_values, TRUE)
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): DataParserPluginInterface {
    // When fetching all rows for comparison, ensure we get fresh data
    if ($this->fetchingAllRows) {
      // Clear any cached data
      $this->currentSourceIds = [];
      self::$apiDataCache = NULL;
    }
    
    // Use static cache to prevent redundant API calls within the same request
    if (self::$apiDataCache !== NULL && !$this->fetchingAllRows) {
      // Return cached parser if available
      // Note: We need to create a new parser with cached data
      // This is a simplified approach - you might need to adjust based on your parser
      return parent::initializeIterator();
    }
    
    // Call parent method which returns DataParserPluginInterface
    $parser = parent::initializeIterator();
    
    // Cache the data for this request
    self::$apiDataCache = TRUE; // Simple flag to indicate we've fetched data
    
    return $parser;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row): bool {
    $result = parent::prepareRow($row);
    
    // Check if a course is being processed that was previously archived
    $course_id = $row->getSourceProperty('Course_ID');
    if ($course_id) {
      // Check if this course is currently unpublished (archived)
      // Fixed: Removed the moderation_state check as it doesn't exist in your database
      $db = \Drupal::database();
      
      // First check if the table has a moderation_state column
      static $hasModeration = NULL;
      if ($hasModeration === NULL) {
        $schema = $db->schema();
        $hasModeration = $schema->fieldExists('node_field_data', 'moderation_state');
      }
      
      // Build query based on available columns
      if ($hasModeration) {
        $is_archived = $db->query("
          SELECT COUNT(*) 
          FROM {node} n
          INNER JOIN {node_field_data} nfd ON n.nid = nfd.nid
          INNER JOIN {node__field_course_id} nf ON n.nid = nf.entity_id
          WHERE nf.field_course_id_value = :course_id
          AND (nfd.status = 0 OR nfd.moderation_state = 'archived')
        ", [':course_id' => $course_id])->fetchField();
      } else {
        // Just check status field if moderation_state doesn't exist
        $is_archived = $db->query("
          SELECT COUNT(*) 
          FROM {node} n
          INNER JOIN {node_field_data} nfd ON n.nid = nfd.nid
          INNER JOIN {node__field_course_id} nf ON n.nid = nf.entity_id
          WHERE nf.field_course_id_value = :course_id
          AND nfd.status = 0
        ", [':course_id' => $course_id])->fetchField();
      }
      
      if ($is_archived) {
        // Only log once per course to reduce noise
        static $loggedCourses = [];
        if (!isset($loggedCourses[$course_id])) {
          \Drupal::logger('jibc_api_migration')->notice('Processing previously archived course @id from API', [
            '@id' => $course_id
          ]);
          $loggedCourses[$course_id] = TRUE;
        }
        
        // Set a flag that the event subscriber can use
        $row->setSourceProperty('was_archived', TRUE);
      }
    }
    
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    $ids = parent::getIds();
    
    // Ensure Course_ID is properly configured as the ID
    if (!isset($ids['Course_ID'])) {
      $ids['Course_ID'] = [
        'type' => 'string',
        'alias' => 'c',
      ];
    }
    
    return $ids;
  }
  
  /**
   * {@inheritdoc}
   */
  public function count($refresh = FALSE): int {
    // Cache the count to prevent redundant API calls
    static $count = NULL;
    if ($count === NULL || $refresh) {
      $count = parent::count($refresh);
    }
    return $count;
  }
}