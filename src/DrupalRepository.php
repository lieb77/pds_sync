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


class DrupalRepository {

    protected $did;

	public function __construct(
    	protected StateInterface $state,
    	protected EntityTypeManagerInterface $entityTypeManager,
    	protected LoggerChannelInterface $logger,
    	protected TimeInterface $time,
    ){
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
     * Creates the IndieWeb Syndication Entity.
     */
    public function createSyndicationEntity($nid, $atUri): void {
    
    	$parts = explode('/', $atUri);
    	$rkey = end($parts);
    
		// Construct the web URL
		$syndicationUrl = "https://bsky.app/profile/paullieberman.net/post/{$rkey}";
			
        try {
            $storage = $this->entityTypeManager->getStorage('indieweb_syndication');
            
            $syndication = $storage->create([
                'entity_id'		 => $nid,
                'entity_type_id' => 'node',
                'url' 			 => $syndicationUrl,
                'at_uri' 		 => $atUri,
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


	/**
     * Lists Syndication Entities.
     */
    public function getSyndications(): array {
    
    	$syndications = [];
		$storage = $this->entityTypeManager->getStorage('indieweb_syndication');
		
		$sids = $storage->getQuery()
			->accessCheck("FALSE")
			->condition("id", 3, ">")
			->execute();

		foreach ($sids as $id) {
			$synd  = $storage->load($id);
			$syndications[] = [
				'url'    =>  $synd->get('url')->value,
				'nid'    => $synd->get('entity_id')->value,
				'at_uri' => $synd->get('at_uri')->value,
			];		
    	}
    	return($syndications);
    }





    /**
     * Create Webmention Entity
     *
     */
    public function createWebmention($source, $target, $author){
        try{
           $stroage = $this->entityTypeManager->getStorage('indieweb_webmention');
           $webmention = $storage->create([
               'source'  => $source,
               'target'  => $target,
               'author'  => $author,
          ]);
          /* Not that simple 
           * source -> string (201) "https://brid.gy/like/bluesky/did:plc:rb5gn4rk5nlx7wutnskfqzk3/at%253A%252F%2...
           * target -> string (26) "/blog/endless-cycle-hatred"
           * type -> string (5) "entry"
           * property -> string (7) "like-of"
           * author_name -> Drupal\Core\Field\FieldItemList#4450 (0)
           * author_photo -> string (130) "https://cdn.bsky.app/img/avatar/plain/did:plc:c4coixkuyqzdzdxn2letikkp/bafkr...
           * author_url -> string (49) "https://bsky.app/profile/susanotcenas.bsky.social"
           * url -> string (103) "https://bsky.app/profile/paullieberman.org/post/3mgybxghcku22#liked_by_did:p...
           */

        catch (\Exception $e) {
            $this->logger->error('Failed to create syndication entity: @msg', ['@msg' => $e->getMessage()]);
        }
    }



// End of class
}
