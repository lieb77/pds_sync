<?php

namespace Drupal\pds_sync\Drush\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\pds_sync\PdsRepository;

/**
  * Drush commands for pds_sync PDS syncing.
  *
  */
class SyncDrushCommands extends DrushCommands {

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The PDS repository service.
     *
     * @var \Drupal\pds_sync\Service\PdsRepository
     */
    protected $pdsRepository;

    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        PdsRepository $pds_repository
    ) {
        parent::__construct();
        $this->entityTypeManager = $entity_type_manager;
        $this->pdsRepository = $pds_repository;
    }

    /**
     * Syncs historical rides to the PDS for a specific year.
     *     
     * @command pds_sync:sync-history
  	 * @param string $year The year to sync (e.g. 2025)
     * @usage drush pds_sync:sync-history 2025
     * @category pds_sync
     */
    public function syncHistory(string $year): void {
        $start = strtotime("$year-01-01 00:00:00");
        $end = strtotime("$year-12-31 23:59:59");

        $nids = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'ride')
            ->condition('created', [$start, $end], 'BETWEEN')
            ->accessCheck(FALSE)
            ->execute();

        if (empty($nids)) {
            $this->logger()->warning(dt('No rides found for year @year.', ['@year' => $year]));
            return;
        }

        $this->output()->writeln(dt('Found @count rides for @year. Starting sync...', [
            '@count' => count($nids),
            '@year' => $year,
        ]));

        foreach ($nids as $nid) {
            $node = $this->entityTypeManager->getStorage('node')->load($nid);
            $this->output()->writeln(dt('Syncing: @title', ['@title' => $node->label()]));
            
            try {
                $this->pdsRepository->syncRide($node);
            } catch (\Exception $e) {
                $this->logger()->error(dt('Failed to sync node @id: @msg', [
                    '@id' => $nid,
                    '@msg' => $e->getMessage(),
                ]));
            }
        }

        $this->logger()->success(dt('Finished syncing @year history.', ['@year' => $year]));
    }
}
