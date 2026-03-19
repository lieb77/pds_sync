<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\pds_sync\PdsRepository;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\indieweb_webmention\Form\SyndicationForm;

/**
 *
 */
class PdsSyncHooks {

    /*
	 * Constructor
	 *
	 */
	public function __construct(
		protected PdsRepository $pdsRepository,
	    protected StateInterface $state,
    	protected TimeInterface $time,
    	protected DateFormatterInterface $dateFormatter,
    	protected LoggerChannelInterface $logger,
    	
	){}


    /**
     * Implements hook_help().
     */
    #[Hook('help')]
    public function help($route_name, RouteMatchInterface $route_match)
    {
        switch ($route_name) {
        case 'help.page.pds_sync':
            $output  = <<<EOF
			  <h2>PDS Sync Help</h2>
			  <p>This module provides integration with Bluesky.</p>
			  <h3>Setup</h3>
			  <ol>
				<li>Obtain an <a href="https://blueskyfeeds.com/en/faq-app-password">App Password</a> for your BlueSky account. Do not use your login password.</li>
				<li>Create a new Key at <a href="/admin/config/system/keys">/admin/config/system/keys</a>. This will be an Authentication key and will hold your App Password.</li>
				<li>Go to the Drupalsky settings at <a href="/admin/config/services/dskysettings">/admin/config/services/dskysettings</a>. Enter your Bluesky handle and select the Key you saved</li>
				<li>Go to your user profile and you will now see a Bluesky tab</li>
			  </ol>
			EOF;

            return $output;
        }
    }

	#[Hook('node_insert')]
	public function onRideInsert(NodeInterface $node): void {
		if ($node->bundle() !== 'ride') return;
		$this->syncRideToPds($node);
		$this->pdsRepository->postRideToTimeline($node);
	}
	
	#[Hook('node_update')]
	public function onRideUpdate(NodeInterface $node): void {
		if ($node->bundle() !== 'ride') return;
		$this->syncRideToPds($node);		
	}
	
	public function syncRideToPds(NodeInterface $node): void {			
		try {
			$result = $this->pdsRepository->syncRide($node);			
			if ($result) {
				$this->logger->info("Ride data synced to PDS for node @id.", ['@id' => $node->id()]);
			} else {
				$this->logger->warning("Local ride @id saved, but PDS data sync failed.", ['@id' => $node->id()]);
			}
		} catch (\Exception $e) {
			$this->logger->critical("PDS Hook crashed: " . $e->getMessage());
		}
	}
		
	#[Hook('node_delete')]
	public function deleteRideCleanup(NodeInterface $node): void {
		if ($node->bundle() === 'ride') {
			// Cleanup the state entry when the node is gone
			$this->state->delete('pds_sync.sync.' . $node->uuid());
		}
	}
	
//	#[Hook('entity_prepare_form')]
// 	public function showSyncStatusOnForm(EntityInterface $entity, $operation, FormStateInterface $form_state): void {
// 		if ($entity->bundle() === 'ride' && $operation === 'edit') {
// 			$last_sync = $this->state->get('pds_sync.sync.' . $entity->uuid());
// 		
// 			if ($last_sync) {
// 				  $date = $this->dateFormatter->format($last_sync, 'short');
// 				  // This appears in the message area only during this form load
// 		 	 	\Drupal::messenger()->addStatus(t('PDS Sync Status: Last pushed on @date.', ['@date' => $date]));
// 			} else {
// 		  	\Drupal::messenger()->addWarning(t('This ride has not been synced to the PDS yet.'));
// 			}
// 		}
// 	}
	
	/**
	 * Add the at_uri field to the indieweb_syndication entity
	 *
	 */
	#[Hook('entity_base_field_info')]
	public function entityBaseFieldInfo(EntityTypeInterface $entity_type) {
		if ($entity_type->id() === 'indieweb_syndication') {
			$fields = [];
			$fields['at_uri'] = BaseFieldDefinition::create('string')
				->setLabel(t('AT Protocol URI'))
				->setDescription(t('The full at:// URI for the Bluesky post.'))
				->setSettings(['max_length' => 255])
				->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => -5])
				->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5]);
				
			return $fields;
		}
	}
	
	
// End of class.
}

