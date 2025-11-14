<?php

namespace Drupal\jibc_api_migration;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateMapDeleteEvent;
use Drupal\migrate\Event\MigrateMapSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\MigrateExecutable as MigrateExecutableBase;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
use Drupal\migrate_tools\MigrateTools;
use Drupal\migrate_tools\SourceFilter;
use Drupal\migrate_tools\IdMapFilter;
use Drupal\migrate_tools\SyncableSourceInterface;
use Drupal\node\Entity\Node;

/**
 * Defines a migrate executable class for drush.
 */
class JIBCMigrateExecutable extends MigrateExecutableBase {

  /**
   * Counters of map statuses.
   *
   * @var array
   *   Set of counters, keyed by MigrateIdMapInterface::STATUS_* constant.
   */
  protected $saveCounters = [
    MigrateIdMapInterface::STATUS_FAILED => 0,
    MigrateIdMapInterface::STATUS_IGNORED => 0,
    MigrateIdMapInterface::STATUS_IMPORTED => 0,
    MigrateIdMapInterface::STATUS_NEEDS_UPDATE => 0,
  ];

  /**
   * Counter of map saves, used to detect the item limit threshold.
   *
   * @var int
   */
  protected $itemLimitCounter = 0;

  /**
   * Counter of map deletions.
   *
   * @var int
   */
  protected $deleteCounter = 0;

  /**
   * Maximum number of items to process in this migration.
   *
   * 0 indicates no limit is to be applied.
   *
   * @var int
   */
  protected $itemLimit = 0;

  /**
   * Frequency (in items) at which progress messages should be emitted.
   *
   * @var int
   */
  protected $feedback = 0;

  /**
   * List of specific source IDs to import.
   *
   * @var array
   */
  protected $idlist = [];

  /**
   * Count of number of items processed so far in this migration.
   *
   * @var int
   */
  protected $counter = 0;

  /**
   * Whether the destination item exists before saving.
   *
   * @var bool
   */
  protected $preExistingItem = FALSE;

  /**
   * List of event listeners we have registered.
   *
   * @var array
   */
  protected $listeners = [];

  /**
   * Source created for use when rolling back missing items.
   *
   * @var \Drupal\migrate\Plugin\MigrateSourceInterface
   */
  protected $source;

  /**
   * {@inheritdoc}
   */
  public function __construct(MigrationInterface $migration, MigrateMessageInterface $message, array $options = []) {
    parent::__construct($migration, $message);
    if (isset($options['limit'])) {
      $this->itemLimit = $options['limit'];
    }
    if (isset($options['feedback'])) {
      $this->feedback = $options['feedback'];
    }
    $this->idlist = MigrateTools::buildIdList($options);

    $this->listeners[MigrateEvents::MAP_SAVE] = [$this, 'onMapSave'];
    $this->listeners[MigrateEvents::MAP_DELETE] = [$this, 'onMapDelete'];
    $this->listeners[MigrateEvents::POST_IMPORT] = [$this, 'onPostImport'];
    $this->listeners[MigrateEvents::POST_ROLLBACK] = [$this, 'onPostRollback'];
    $this->listeners[MigrateEvents::PRE_ROW_SAVE] = [$this, 'onPreRowSave'];
    $this->listeners[MigrateEvents::POST_ROW_DELETE] = [$this, 'onPostRowDelete'];
    $this->listeners[MigratePlusEvents::PREPARE_ROW] = [$this, 'onPrepareRow'];
    foreach ($this->listeners as $event => $listener) {
      \Drupal::service('event_dispatcher')->addListener($event, $listener);
    }
  }

  /**
   * Count up any map save events.
   *
   * @param \Drupal\migrate\Event\MigrateMapSaveEvent $event
   *   The map event.
   */
  public function onMapSave(MigrateMapSaveEvent $event) {
    // Only count saves for this migration.
    if ($event->getMap()->getQualifiedMapTableName() == $this->migration->getIdMap()->getQualifiedMapTableName()) {
      $fields = $event->getFields();
      $this->itemLimitCounter++;
      // Distinguish between creation and update.
      if ($fields['source_row_status'] == MigrateIdMapInterface::STATUS_IMPORTED &&
        $this->preExistingItem
      ) {
        $this->saveCounters[MigrateIdMapInterface::STATUS_NEEDS_UPDATE]++;
      }
      else {
        $this->saveCounters[$fields['source_row_status']]++;
      }
    }
  }

  /**
   * Count up any rollback events.
   *
   * @param \Drupal\migrate\Event\MigrateMapDeleteEvent $event
   *   The map event.
   */
  public function onMapDelete(MigrateMapDeleteEvent $event) {
    $this->deleteCounter++;
  }

  /**
   * Return the number of items created.
   *
   * @return int
   *   The number of items created.
   */
  public function getCreatedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_IMPORTED];
  }

  /**
   * Return the number of items updated.
   *
   * @return int
   *   The updated count.
   */
  public function getUpdatedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_NEEDS_UPDATE];
  }

  /**
   * Return the number of items ignored.
   *
   * @return int
   *   The ignored count.
   */
  public function getIgnoredCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_IGNORED];
  }

  /**
   * Return the number of items that failed.
   *
   * @return int
   *   The failed count.
   */
  public function getFailedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_FAILED];
  }

  /**
   * Return the total number of items processed.
   *
   * Note that STATUS_NEEDS_UPDATE is not counted, since this is typically set
   * on stubs created as side effects, not on the primary item being imported.
   *
   * @return int
   *   The processed count.
   */
  public function getProcessedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_IMPORTED] +
      $this->saveCounters[MigrateIdMapInterface::STATUS_NEEDS_UPDATE] +
      $this->saveCounters[MigrateIdMapInterface::STATUS_IGNORED] +
      $this->saveCounters[MigrateIdMapInterface::STATUS_FAILED];
  }

  /**
   * Return the number of items rolled back.
   *
   * @return int
   *   The rollback count.
   */
  public function getRollbackCount() {
    return $this->deleteCounter;
  }

  /**
   * Reset all the per-status counters to 0.
   */
  protected function resetCounters() {
    foreach ($this->saveCounters as $status => $count) {
      $this->saveCounters[$status] = 0;
    }
    $this->deleteCounter = 0;
  }

  /**
   * React to migration completion.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The map event.
   */
  public function onPostImport(MigrateImportEvent $event) {

    /**
     * Trackers for JIBC Notification Email
     */
    if ($event->getMigration()->getBaseId() == 'new_courses') {

      $tempstore = \Drupal::service('tempstore.private')->get('jibc_api_migration');
      $key = $tempstore->get('trigger');
      $email_service = \Drupal::service('jibc_api_migration.email');
      $message = t("Processed @numitems courses (@created created, @updated updated, @unpublished unpublished, @failures failed, @ignored ignored)",
        [
          '@created' => $this->getCreatedCount(),
          '@numitems' => $this->getProcessedCount() + $tempstore->get('unpublished'),
          '@updated' => $this->getUpdatedCount(),
          '@unpublished' => $tempstore->get('unpublished'),
          '@failures' => $this->getFailedCount(),
          '@ignored' => $this->getIgnoredCount(),
        ]);
        \Drupal::messenger()->addMessage($message);
      $email_service->sendEmail($message, 'user');
      \Drupal::logger('Course Refresh')->notice("Courses refresh ran successfully");

    }

    $migrate_last_imported_store = \Drupal::keyValue('migrate_last_imported');
    $migrate_last_imported_store->set($event->getMigration()->id(), round(microtime(TRUE) * 1000));
    $this->progressMessage();
    $this->removeListeners();
  }

  /**
   * Clean up all our event listeners.
   */
  protected function removeListeners() {
    foreach ($this->listeners as $event => $listener) {
      \Drupal::service('event_dispatcher')->removeListener($event, $listener);
    }
  }

  /**
   * Emit information on what we've done.
   *
   * Either since the last feedback or the beginning of this migration.
   *
   * @param bool $done
   *   TRUE if this is the last items to process. Otherwise FALSE.
   */
  protected function progressMessage($done = TRUE) {
    $processed = $this->getProcessedCount();
    if ($done) {
      $singular_message = "Processed 1 course (@created created, @updated updated, @failures failed, @ignored ignored) - done with '@name'";
      $plural_message = "Processed @numitems courses (@created created, @updated updated, @failures failed, @ignored ignored) - done with '@name'";
    }
    else {
      $singular_message = "Processed 1 course (@created created, @updated updated, @failures failed, @ignored ignored) - continuing with '@name'";
      $plural_message = "Processed @numitems courses (@created created, @updated updated, @failures failed, @ignored ignored) - continuing with '@name'";
    }
    $this->message->display(\Drupal::translation()->formatPlural($processed,
      $singular_message, $plural_message,
        [
          '@numitems' => $processed,
          '@created' => $this->getCreatedCount(),
          '@updated' => $this->getUpdatedCount(),
          '@failures' => $this->getFailedCount(),
          '@ignored' => $this->getIgnoredCount(),
          '@name' => $this->migration->id(),
        ]
    ));
  }

  /**
   * React to rollback completion.
   *
   * @param \Drupal\migrate\Event\MigrateRollbackEvent $event
   *   The map event.
   */
  public function onPostRollback(MigrateRollbackEvent $event) {
    $migrate_last_imported_store = \Drupal::keyValue('migrate_last_imported');
    $migrate_last_imported_store->set($event->getMigration()->id(), FALSE);
    $this->rollbackMessage();
    $this->removeListeners();

    /**
     * Trackers for JIBC Notification Email
     */

    $tempstore = \Drupal::service('tempstore.private')->get('jibc_api_migration');
    $tempstore->set('unpublished', $this->getRollbackCount());
  }

  /**
   * Emit information on what we've done.
   *
   * Either since the last feedback or the beginning of this migration.
   *
   * @param bool $done
   *   TRUE if this is the last items to rollback. Otherwise FALSE.
   */
  protected function rollbackMessage($done = TRUE) {
    $rolled_back = $this->getRollbackCount();
    if ($done) {
      $singular_message = "Unpublished 1 course missing from JIBC API source - done with '@name'";
      $plural_message = "Unpublished @numitems courses missing from JIBC API source - done with '@name'";
    }
    else {
      $singular_message = "Unpublished 1 course missing from JIBC API source - continuing with '@name'";
      $plural_message = "Unpublished @numitems courses missing from JIBC API source - continuing with '@name'";
    }
    $this->message->display(\Drupal::translation()->formatPlural($rolled_back,
      $singular_message, $plural_message,
      [
        '@numitems' => $rolled_back,
        '@name' => $this->migration->id(),
      ]
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function rollbackMissingItems() {
    // Only begin the rollback operation if the migration is currently idle.
    if ($this->migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
      $this->message->display($this->t("Migration '@id' is busy with another operation: @status",
        [
          '@id' => $this->migration->id(),
          '@status' => $this->migration->getStatusLabel(),
        ]),
        'error');
      return MigrationInterface::RESULT_FAILED;
    }

    if (!$this->migration->getSourcePlugin() instanceof SyncableSourceInterface) {
      $message = $this->t("Migration '@id' does not support unpublishing courses missing from the JIBC API source", [
        '@id' => $this->migration->id(),
      ]);
      $this->message->display($message, 'error');
      return MigrationInterface::RESULT_FAILED;
    }

    // Announce that rollback is about to happen.
    // This is old code - causing error on cron
    // $this->getEventDispatcher()->dispatch(MigrateEvents::PRE_ROLLBACK, new MigrateRollbackEvent($this->migration));
    // This is newer code - hopefully allows import to run on cron
    $this->getEventDispatcher()->dispatch(new MigrateRollbackEvent($this->migration), MigrateEvents::PRE_ROLLBACK);

    // Optimistically assume things are going to work out; if not, $return will
    // be updated to some other status.
    $return = MigrationInterface::RESULT_COMPLETED;

    $this->migration->setStatus(MigrationInterface::STATUS_ROLLING_BACK);
    $id_map = $this->migration->getIdMap();

    // We can't use the source plugin as-is because we need ALL potential rows
    // and certain plugin configurations will only return a subset.
    $source_config = $this->migration->getSourceConfiguration();
    $source_config['all_rows'] = TRUE;

    /** @var \Drupal\migrate\Plugin\MigrateSourceInterface $source */
    $this->source = \Drupal::service('plugin.manager.migrate.source')
      ->createInstance($source_config['plugin'], $source_config, $this->migration);
    $source_ids = $this->source->sourceIds();
    // Rollback any rows that are not returned from the source plugin.
    foreach ($id_map as $map_row) {
      $source_key = $id_map->currentSource();
      $destination_key = $id_map->currentDestination();

      // If this one wasn't imported, or if we're still receiving it from the
      // source plugin, then we don't need to do anything.
      if (!$destination_key || !$source_key || in_array($source_key, $source_ids)) {
        continue;
      }

      $event = $this->getEventDispatcher()
        ->dispatch(MigratePlusEvents::MISSING_SOURCE_ITEM, new MigrateRowDeleteEvent($this->migration, $destination_key));
      if (!$event->isPropagationStopped()) {
        $this->rollbackCurrentRow();
      }

      // Check for memory exhaustion.
      if (($return = $this->checkStatus()) != MigrationInterface::RESULT_COMPLETED) {
        break;
      }

      // If anyone has requested we stop, return the requested result.
      if ($this->migration->getStatus() == MigrationInterface::STATUS_STOPPING) {
        $return = $this->migration->getInterruptionResult();
        $this->migration->clearInterruptionResult();
        break;
      }
    }

    // Notify modules that rollback attempt was complete.
    $this->getEventDispatcher()->dispatch(MigrateEvents::POST_ROLLBACK, new MigrateRollbackEvent($this->migration));
    $this->migration->setStatus(MigrationInterface::STATUS_IDLE);

    return $return;
  }

  /**
   * Roll back the current row.
   */
  protected function rollbackCurrentRow() {
    $id_map = $this->migration->getIdMap();
    $destination_key = $id_map->currentDestination();
    //$destination = $this->migration->getDestinationPlugin();
    //var_dump($this->migration->getIdMap());
    if ($destination_key) {
      $map_row = $id_map->getRowByDestination($destination_key);

      //if ($map_row['rollback_action'] == MigrateIdMapInterface::ROLLBACK_DELETE) {
        $this->getEventDispatcher()
          ->dispatch(MigrateEvents::PRE_ROW_DELETE, new MigrateRowDeleteEvent($this->migration, $destination_key));

        // Unpublished the node.
        $this->unpublishCourse($destination_key);

        $this->getEventDispatcher()
          ->dispatch(MigrateEvents::POST_ROW_DELETE, new MigrateRowDeleteEvent($this->migration, $destination_key));
      //}
    }
  }

  /**
   * React to an item about to be imported.
   *
   * @param \Drupal\migrate\Event\MigratePreRowSaveEvent $event
   *   The pre-save event.
   */
  public function onPreRowSave(MigratePreRowSaveEvent $event) {
    $id_map = $event->getRow()->getIdMap();
    if (!empty($id_map['destid1'])) {
      $this->preExistingItem = TRUE;
    }
    else {
      $this->preExistingItem = FALSE;
    }
  }

  /**
   * React to item rollback.
   *
   * @param \Drupal\migrate\Event\MigrateRowDeleteEvent $event
   *   The post-save event.
   */
  public function onPostRowDelete(MigrateRowDeleteEvent $event) {
    if ($this->feedback && ($this->deleteCounter) && $this->deleteCounter % $this->feedback == 0) {
      $this->rollbackMessage(FALSE);
      $this->resetCounters();
    }
  }

  /**
   * React to a new row.
   *
   * @param \Drupal\migrate_plus\Event\MigratePrepareRowEvent $event
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function onPrepareRow(MigratePrepareRowEvent $event) {
    // TODO: remove after 8.6 suppor is sunset.
    // @see https://www.drupal.org/project/migrate_tools/issues/3008316
    if (!empty($this->idlist)) {
      $row = $event->getRow();
      // TODO: replace for $source_id = $row->getSourceIdValues();
      // when https://www.drupal.org/node/2698023 is fixed.
      $migration = $event->getMigration();
      $source_id = array_merge(array_flip(array_keys($migration->getSourcePlugin()
        ->getIds())), $row->getSourceIdValues());
      $skip = TRUE;
      foreach ($this->idlist as $item) {
        if (array_values($source_id) == $item) {
          $skip = FALSE;
          break;
        }
      }
      if ($skip) {
        throw new MigrateSkipRowException('Skipped due to idlist.', FALSE);
      }
    }
    if ($this->feedback && ($this->counter) && $this->counter % $this->feedback == 0) {
      $this->progressMessage(FALSE);
      $this->resetCounters();
    }
    $this->counter++;
    if ($this->itemLimit && ($this->itemLimitCounter + 1) >= $this->itemLimit) {
      $event->getMigration()->interruptMigration(MigrationInterface::RESULT_COMPLETED);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getSource() {
    return new SourceFilter(parent::getSource(), $this->idlist);
  }

  /**
   * {@inheritdoc}
   */
  protected function getIdMap() {
    return new IdMapFilter(parent::getIdMap(), $this->idlist);
  }

  /**
   * Unpublished node.
   *
   * @param array $nid
   *   The node entity.
   */
  protected function unpublishCourse(array $nid) {
    if ($node = Node::load(reset($nid))) {
      if($node->isPublished()){
        $node->setPublished(FALSE);
        $node->set('moderation_state', "archived");
        $node->save();
        $this->deleteCounter++;
      }
    }
  }
}
