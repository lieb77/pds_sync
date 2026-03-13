<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\pds_sync\PdsRepository;
use Drupal\pds_sync\Model\Rides;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Render\Renderer; 

/**
 * Returns responses for Drupalsky routes.
 */
final class PdsSyncController extends ControllerBase {

	/**
	* The controller constructor.
	*/
	public function __construct(
	    private Renderer $renderer,
		private PdsRepository $pdsRepository,
	){} 

	public static function create(ContainerInterface $container): self {
		return new self(
			$container->get('renderer'),
			$container->get('pds_sync.pds_repo'),
		);
	}

  /**
   * Admin
   *
   * Return a render array.
   */
  public function admin() {
	
    return [
        '#type' => 'item',
        '#markup' => $this->t("PDS Sync Admin Console"),
    ];
  }
  
	/**
	 * Update
	 *
	 * Return a render array.
	 */
	public function update($rkey) {
		// 1. Logic to sync/update
		$this->pdsRepository->syncByRkey($rkey);
		
		// 2. Get the fresh data for this one ride
		$ride_data = $this->pdsRepository->getSingleRide($rkey);
		
		// 3. Render JUST the row component (SDC)
		$build = [
			'#type' => 'component',
			'#component' => 'pds-sync:pds-ride-row',
			'#props' => $ride_data,
		];
		
		$html = $this->renderer->renderInIsolation($build);
		return new Response($html);
	}
	
	/**
	 * Delete
	 *
	 * Return a render array.
	 */
	public function delete($rkey) {
		// 1. Logic to delete from PDS
		$success = $this->pdsRepository->deleteRide($rkey);
		
		if ($success) {
		// Return an empty 200 Response. htmx will remove the row.
		return new Response('', 200);
		}
		
		// Return a 500 or 400 if it fails so htmx doesn't delete the row
		return new Response('Deletion failed', 500);
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

  // End of class.
}
