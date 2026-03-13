<?php

declare(strict_types=1);

namespace Drupal\pds_sync;

use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

use Drupal\pds_sync\AtprotoClient;
use Drupal\pds_sync\Endpoints;

class PdsRepository {

    protected $did; 

	public function __construct(
		protected AtprotoClient $atprotoClient,
    	protected EndPoints $endpoints,
    	protected StateInterface $state,
    	protected EntityTypeManagerInterface $entityTypeManager,
    	protected LoggerChannelInterface $logger,
    ){
        $this->did = $atprotoClient->getDid();
    }

	/**
	 *
	 *
	 */ 
	public function syncRide(NodeInterface $node) {
		$rkey = $node->uuid();
		
		// Must dereference the bike
		$bid      = $node->field_bike->target_id;
		$bikeName = $bid ? Node::load($bid)->getTitle() : 'Unknown Bike';
		
		// Get your field_ridedate string (e.g., "2026-03-10")
		$rideDateRaw = $node->get('field_ridedate')->value;
		// Append time and Z for AT Protocol compliance
		$isoDate = $rideDateRaw ? $rideDateRaw . 'T12:00:00Z' : date('c', $node->getCreatedTime());
		
		$record = [
			'$type' => 'net.paullieberman.bike.ride',
			'createdAt' => $isoDate, // ADD THIS: The field the network uses for sorting
			'route' => $node->getTitle(),
			'miles' => (int) $node->get('field_miles')->value,
			'date'  => $rideDateRaw, // Keep your original date field for your own lexicon
			'bike'  => $bikeName,
			'url'   => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
			'body'  => MailFormatHelper::htmlToText($node->body->value),
		];
		
		return $this->atprotoClient->request('POST', $this->endpoints->putRecord(), [
			'json' => [
				'repo' => $this->atprotoClient->getDid(),
				'collection' => 'net.paullieberman.bike.ride',
				'rkey' => $rkey,
				'record' => $record,
			],
		]);
	}

	/**
      * Get Rides
      */
	public function getRides() {
		$endpoint = $this->endpoints->listRecords();
		$query = ['query' => [
			'repo' => $this->did,
			'collection' => 'net.paullieberman.bike.ride'
		]];
		
		$data = $this->atprotoClient->request('GET', $endpoint, $query);
		
		// Map through the records and attach the status before returning
		$rides = array_map(function($record) {
			$record_array = (array) $record->value;
			
			// Extract the rkey (UUID) from the AT-URI
			$parts = explode('/', $record->uri);
			$record_array['rkey'] = end($parts);
			
			// Attach reconciliation data
			$record_array['sync_meta'] = $this->getReconciledStatus($record_array);
			
			return $record_array;
		}, $data->records);
		usort($rides, function ($a, $b) {
         	return strcmp($b['date'], $a['date']);
    	});
    	return $rides;
	}


	/**
	 * Synchronizes a specific PDS record by its rkey (UUID).
	 */
	public function syncByRkey(string $rkey): bool {
		// 1. Find the local node by UUID
		$nodes = $this->entityTypeManager->getStorage('node')
			->loadByProperties(['uuid' => $rkey]);
		
		$node = reset($nodes);
		
		if (!$node) {
			// IT Vet Log: Don't just fail silently; log the Ghost attempt.
			$this->logger->error('Sync failed: No local node found for UUID @uuid', ['@uuid' => $rkey]);
			return false;
		}
		
		// 2. Reuse your existing sync logic from the DrupalSky extraction
		// This likely calls your atproto client to PUT/POST the record.
		$result = $this->syncRide($node);
		
		if ($result) {
			// 3. Update the State store to mark it as Synced
			// We use the UUID as the key to keep it portable across environments.
			$this->state->set('pds_sync.sync.' . $node->uuid(), $this->time->getRequestTime());
		
			$this->logger->info('Manual dashboard sync successful for ride @uuid', ['@uuid' => $rkey]);
			return true;
		}		
		return false;
	}


	/**
	 * Deletes a record from the PDS.
	 */
	public function deleteRide(string $rkey): bool {
		$endpoint = $this->endpoints->deleteRecord();
		
		$payload = [
			'repo' => $this->did,
			'collection' => 'net.paullieberman.bike.ride',
			'rkey' => $rkey,
		];
		
		try {
			// AT Protocol DELETE requests typically use POST to the com.atproto.repo.deleteRecord endpoint
			$response = $this->atprotoClient->request('POST', $endpoint, [
				'json' => $payload,
			]);
			
			// If successful, clean up the local state so we don't have orphaned sync data
			$this->state->delete('pds_sync.sync.' . $rkey);
			
			$this->logger->info('Deleted ride @rkey from PDS.', ['@rkey' => $rkey]);
			return true;
		}
		catch (\Exception $e) {
			$this->logger->error('Failed to delete ride @rkey: @message', [
				'@rkey' => $rkey,
				'@message' => $e->getMessage(),
			]);
			return false;
		}
	}


	/**
	 * Reconciles PDS data with Drupal State and Node data.
	 */
	private function getReconciledStatus(array $pds_record): array {
		$uuid = $pds_record['rkey'];
		
		// 1. Find the Node.
		$nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);
		$node = reset($nodes);
		
		if (!$node) {
			return [
				'status' => 'ghost',
				'label' => 'Ghost (PDS Only)',
				'class' => 'status-danger',
			];
		}
		
		// 2. Check the local State.
		$last_sync_timestamp = $this->state->get('pds_sync.sync.' . $node->uuid());
		
		// 3. Compare changed time vs last sync.
		$is_changed_since_sync = $node->getChangedTime() > ($last_sync_timestamp ?? 0);
		
		if (!$last_sync_timestamp) {
			return [
				'status' => 'untracked',
				'label' => 'Exists on PDS (Untracked Locally)',
				'class' => 'status-info',
				'node' => $node,
			];
		}
		
		if ($is_changed_since_sync) {
			return [
				'status' => 'pending',
				'label' => 'Changes Pending Sync',
				'class' => 'status-warning',
				'node' => $node,
			];
		}
		
		return [
			'status' => 'synced',
			'label' => 'Synced',
			'class' => 'status-success',
			'node' => $node,
		];
	}






}
