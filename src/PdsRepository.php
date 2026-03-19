<?php

declare(strict_types=1);

namespace Drupal\pds_sync;

use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Component\Datetime\TimeInterface;

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
    	protected TimeInterface $time,
    ){
        $this->did = $atprotoClient->getDid();
    }


	public function createFeedRecord() {	
		$feedRecord = [
			'repo' => 'did:plc:ntnmdg6fuvogzr6khf7agoqf', // Your DID
			'collection' => 'app.bsky.feed.generator',
			'rkey' => 'ride-log', // This matches the 'short name' in your URIs
			'record' => [
				'did' => 'did:web:paullieberman.net', // The DID of your Express App
				'displayName' => "Lieb's Ride Log 🚲",
				'description' => 'Automated cycling ride logs synced from paullieberman.net.',
				'avatar' => [
					'$type' => 'blob',
					'ref' => [
						'$link' => 'bafkreifuesssw2dbn7hqcmyattuvogimimbbfknvg7uhh7jltdjvijrkvq',
					],
					'mimeType' => 'image/jpeg',
					'size' => 864807,
				],
				'createdAt' => date('c'),
			],
		];
		
		return $this->atprotoClient->request('POST', $this->endpoints->putRecord(), [
			'json' => $feedRecord,
		]);
	
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
     * Get Rides
     */
	public function getRides() {
    $endpoint = $this->endpoints->listRecords();
    $all_records = [];
    $cursor = NULL;

    // 1. The Paginator: Get everything from the PDS
    do {
        $query = ['query' => [
            'repo' => $this->did,
            'collection' => 'net.paullieberman.bike.ride',
            'limit' => 100,
        ]];

        if ($cursor) {
            $query['query']['cursor'] = $cursor;
        }

        $response = $this->atprotoClient->request('GET', $endpoint, $query);
        $all_records = array_merge($all_records, $response->records);
        $cursor = $response->cursor ?? NULL;
    } while ($cursor);

    // 2. The Transformer: Convert stdClass to your enriched Array structure
    $rides = array_map(function($record) {
        $record_array = (array) $record->value;

        // Extract the rkey (UUID) from the AT-URI
        $parts = explode('/', $record->uri);
        $record_array['rkey'] = end($parts);

        // Attach reconciliation data (the status info)
        $record_array['sync_meta'] = $this->getReconciledStatus($record_array);

        return $record_array;
    }, $all_records);

    // 3. The Sorter: Reverse chronological by date string
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


	/**
	 * Create a regualar Bluesky post
	 *
	 */
	public function postRideToTimeline(NodeInterface $node) {
		$rkey = $node->uuid();
		$rideDateRaw = $node->get('field_ridedate')->value;
		$createdAt = date('c', strtotime($rideDateRaw)); 
		
		$textParts = [
			"🚲Lieb's Ride Log🚲",
			"Route: " . $node->label(),
			"Date: " . $rideDateRaw,
			"Distance: " . $node->get('field_miles')->value . " miles",
			"Bike: " . ($node->get('field_bike')->entity?->label() ?? 'N/A'),
			"", // Empty line for spacing
		    "#bicycle #bikeride #bikepacking", 
		];
		
		$text = implode("\n", $textParts);
		$facets = $this->createTagFacets($text, ['bicycle', 'bikeride', 'bikepacking']);
		
		// Safely truncate the main text field just in case
		if (mb_strlen($text) > 300) {
		    $text = mb_substr($text, 0, 250) . '...';
		}
		
		$body = MailFormatHelper::htmlToText($node->body->value);		
		$uri  = $node->toUrl()->setAbsolute()->toString();
		
		$postRecord = [
		'repo' => $this->did,
		'collection' => 'app.bsky.feed.post',
		'record' => [
		  '$type' => 'app.bsky.feed.post',
		  'text' => $text,
		  'facets' => $facets,
		  'createdAt' => $createdAt, 
		  'tags' => ['lieb-ride-log'],
		  'embed' => [
			'$type' => 'app.bsky.embed.external',
			'external' => [
			  'uri' => $uri,
			  'title' => "Ride: " . $node->label(),
			  'description' => $body,
			],
		  ],
		],
		];
		
		$response = $this->atprotoClient->request('POST', $this->endpoints->createRecord(), [
			'json' => $postRecord,
		]);
		if (isset($response->uri)) {
    		$parts = explode('/', $response->uri);
    		$rkey = end($parts);
    
			// Construct the web URL
			$url = "https://bsky.app/profile/paullieberman.net/post/{$rkey}";
			
			// Call your syndication method
			$this->createSyndicationEntity($node->id(), $url);
		}		
		return $response;
	}
	

	private function createTagFacets($text, $tags){			
		$facets = [];
		
		foreach ($tags as $tag) {
			$search = '#' . $tag;
			$pos = strpos($text, $search); // Byte position
			if ($pos !== false) {
				$facets[] = [
					'index' => [
						'byteStart' => $pos,
						'byteEnd' => $pos + strlen($search),
					],
					'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]]
				];
			}
		}
		return $facets;
			
	}	
	
	  /**
     * Creates the IndieWeb Syndication Entity.
     */
    private function createSyndicationEntity($nid, $syndicationUrl): void {
        try {
            $storage = $this->entityTypeManager->getStorage('indieweb_syndication');
            
            $syndication = $storage->create([
                'entity_id' => $nid,
                'entity_type_id' => 'node',
                'url' => $syndicationUrl,
            ]);
            
            $syndication->save();
            $this->logger->info('Created syndication entity for node @nid: @url', [
                '@nid' => $nid,
                '@url' => $syndicationUrl,
            ]);
        }
        catch (\Exception $e) {
            $this->logger->error('Failed to create syndication entity: @msg', ['@msg' => $e->getMessage()]);
        }
    }
	
// End of class
}
