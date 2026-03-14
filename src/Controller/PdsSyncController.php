<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Render\Renderer; 
use Drupal\Core\Datetime\DateFormatterInterface;

use Drupal\pds_sync\PdsRepository;
use Drupal\pds_sync\Model\Rides;
use Drupal\pds_sync\PdsSyncManager;

/**
 * Returns responses for Drupalsky routes.
 */
final class PdsSyncController extends ControllerBase {

	/**
	* The controller constructor.
	*/
	public function __construct(
	    private Renderer $renderer,
	    private PdsSyncManager $pdsSyncManager,
		private PdsRepository $pdsRepository,
		private DateFormatterInterface $dateFormatter,
	){} 

	public static function create(ContainerInterface $container): self {
		return new self(
			$container->get('renderer'),
			$container->get('pds_sync.manager'),
    		$container->get('pds_sync.repository'),
    		$container->get('date.formatter')
		);
	}



	/**
	* PDS Admin Dashboard
	*
	*/
	public function dashboard() {
		$nodes = $this->pdsSyncManager->getRecentRides(25);
		$pds_rides = $this->pdsRepository->getRides();
		
		$rides = [];
		foreach ($nodes as $node) {
			// We map Drupal fields to the keys the SDC 'rides' component expects
			$rides[] = [
				'route' => $node->label(),
				'date' => $node->get('field_ridedate')->value, // Ensure this matches your field name
				'miles' => $node->get('field_miles')->value,
				'bike' => $node->get('field_bike')->entity?->label(), // If it's a taxonomy/entity ref
				'rkey' => $node->uuid(),
				'sync_meta' => $this->pdsSyncManager->getReconciledStatus($node, $pds_rides),
			];
		}
		
		return [
			'#type' => 'component',
			'#component' => 'pds_sync:rides',
			'#props' => [
				'rides' => $rides,
			],			
		];
	}

	public function update($rkey) {
		$success = $this->pdsRepository->syncByRkey($rkey);
		
		if ($success) {
			// 1. Load the node to get the label/date for the row
			$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $rkey]);
			$node = reset($nodes);
			
			// 2. Prepare data, but MANUALLY set the sync_meta to 'Synced'
			$ride_data = $this->prepareRideData($node, []); // Pass empty PDS array
			$ride_data['sync_meta'] = [
			  'label' => 'Synced',
			  'class' => 'status-synced',
			  'can_sync' => FALSE,
			  'can_delete' => TRUE,
			];
			
			// 3. Return the fresh row
			$build = [
			  '#type' => 'component',
			  '#component' => 'pds_sync:pds-ride-row',
			  '#props' => ['ride' => $ride_data],
			];
			
			$html = $this->renderer->renderInIsolation($build);
			return new Response(trim($html));
		}
		
		return new Response("<div class='status-error'>Sync failed for $rkey</div>", 500);
	}


	public function delete($rkey) {
		\Drupal::logger('pds_sync')->warning('HTMX Delete successfully reached the controller for rkey: @rkey', [
		'@rkey' => $rkey,
		]);
		
		// HTMX data-hx-swap="delete" will remove the element when it receives this empty response
		return new \Symfony\Component\HttpFoundation\Response('');
	}  
 
	/**
	* Rides.
	*
	* Return a render array.
	*/
	public function rides() {
		$rides = $this->pdsRepository->getRides();	
		
		return [
			'#type' => 'component',
			'#component' => 'pds_sync:rides',
			'#props' => ['rides' => $rides],
		];
	}
  
  

  /**
   * Logout.
   */
  public function logout() {
    $this->service->logout();

    return [
      '#type' => 'item',
      '#markup' => $this->t("Your Bluesky session has been cleared"),
    ];
  }



	/**
	 * Prepares data for the pds-ride-row SDC.
	 */
	private function prepareRideData(\Drupal\node\NodeInterface $node, array $pds_rides): array {
	  // 1. Extract basic field data
	  $date_value = $node->get('field_ridedate')->value; // Assuming ISO string
	  $miles = $node->get('field_miles')->value;
	  
	  // Get the bike label from the entity reference
	  $bike_entity = $node->get('field_bike')->entity;
	  $bike_label = $bike_entity ? $bike_entity->label() : 'N/A';
	
	  // 2. Determine Sync Status via the Manager
	  // This is where your Yellow/Green/White logic lives
	  $sync_meta = $this->pdsSyncManager->getReconciledStatus($node, $pds_rides);
	
	  return [
		'route' => $node->label(),
		'date' => $date_value,
		'miles' => $miles,
		'bike' => $bike_label,
		'rkey' => $node->uuid(),
		'sync_meta' => $sync_meta,
	  ];
	}



  // End of class.
}
