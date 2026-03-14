<?php
declare(strict_types=1);

namespace Drupal\pds_sync;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;

/**
 * Orchestrates the synchronization between Drupal Nodes and the PDS.
 */
class PdsSyncManager {

    public function __construct(
        protected EntityTypeManagerInterface $entityTypeManager,
        protected StateInterface $state,
        protected PdsRepository $pdsRepository,
        protected TimeInterface $time,
    ) {}

    /**
     * Fetches the most recent rides from Drupal.
     */
    public function getRecentRides(int $limit = 10): array {
        $storage = $this->entityTypeManager->getStorage('node');
        $query = $storage->getQuery()
            ->condition('type', 'ride')
            ->condition('status', 1)
            ->sort('field_ridedate', 'DESC')
            ->range(0, $limit)
            ->accessCheck(TRUE);

        $nids = $query->execute();
        return $storage->loadMultiple($nids);
    }


	/**
	 * Finds a local ride node by its PDS rkey (UUID).
	 */
	public function getLocalNodeByRkey(string $rkey): ?\Drupal\node\NodeInterface {
		$nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
			'uuid' => $rkey,
			'type' => 'ride', // Ensuring we only look at ride nodes
		]);
		
		return $nodes ? reset($nodes) : NULL;
	}


    /**
     * Checks the local sync state for a given node.
     */
    public function getLocalSyncStatus(NodeInterface $node): ?int {
        return $this->state->get('pds_sync.sync.' . $node->uuid());
    }

    /**
     * Marks a node as synced locally.
     */
    public function setLocalSyncStatus(NodeInterface $node): void {
        $this->state->set('pds_sync.sync.' . $node->uuid(), $this->time->getRequestTime());
    }

    /**
     * The "Rolling Window" Pruning:
     * Keeps the PDS clean by ensuring only the latest X records exist.
     */
    public function prunePdsFeed(int $keep = 15): int {
        $all_pds_rides = $this->pdsRepository->getRides();
        if (count($all_pds_rides) <= $keep) {
            return 0;
        }

        // Sort PDS records by date descending
        usort($all_pds_rides, fn($a, $b) => strcmp($b['date'], $a['date']));

        $to_delete = array_slice($all_pds_rides, $keep);
        $count = 0;
        foreach ($to_delete as $ride) {
            if ($this->pdsRepository->deleteRide($ride['rkey'])) {
                $count++;
            }
        }
        return $count;
    }
    
    public function getReconciledStatus(NodeInterface $node, array $pds_rides): array {
  $uuid = $node->uuid();
  $is_on_pds = false;

  // Search the PDS results for this UUID
  foreach ($pds_rides as $pds_ride) {
    if ($pds_ride['rkey'] === $uuid) {
      $is_on_pds = true;
      break;
    }
  }

  $local_sync = $this->getLocalSyncStatus($node);

  if ($is_on_pds) {
    return [
      'label' => 'Synced',
      'class' => 'status-synced',
      'can_sync' => FALSE,
      'can_delete' => TRUE,
    ];
  }

  if ($local_sync) {
    return [
      'label' => 'Archived',
      'class' => 'status-archived',
      'can_sync' => TRUE,
      'can_delete' => FALSE,
    ];
  }

  return [
    'label' => 'Untracked',
    'class' => 'status-untracked',
    'can_sync' => TRUE,
    'can_delete' => FALSE,
  ];
}
}