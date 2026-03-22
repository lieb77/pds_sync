<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\pds_sync\PdsRepository;

/**
 * Provides hook implementations for the PDS Sync module.
 */
class PdsSyncHooks {

    /**
     * Constructs a new PdsSyncHooks instance.
     *
     * @param \Drupal\pds_sync\PdsRepository $pdsRepository
     *   The PDS repository.
     * @param \Drupal\Core\State\StateInterface $state
     *   The state service.
     * @param \Drupal\Component\Datetime\TimeInterface $time
     *   The time service.
     * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
     *   The date formatter service.
     * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
     *   The logger channel.
     */
    public function __construct(
        protected PdsRepository $pdsRepository,
        protected StateInterface $state,
        protected TimeInterface $time,
        protected DateFormatterInterface $dateFormatter,
        protected LoggerChannelInterface $logger,
    ) {}

    /**
     * Implements hook_help().
     *
     * @param string $route_name
     *   The name of the route being accessed.
     * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
     *   The corresponding route match object.
     *
     * @return array|null
     *   An array of help text to display, or NULL if no help is available.
     */
    #[Hook('help')]
    public function help(string $route_name, RouteMatchInterface $route_match): ?array
    {
        if ($route_name === 'help.page.pds_sync') {
            $output = <<<EOF
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

            return ['#markup' => $output];
        }

        return NULL;
    }

    /**
     * Implements hook_node_insert().
     *
     * @param \Drupal\node\NodeInterface $node
     *   The node being inserted.
     */
    #[Hook('node_insert')]
    public function onRideInsert(NodeInterface $node): void
    {
        if ($node->bundle() !== 'ride') {
            return;
        }

        $this->syncRideToPds($node);
        $this->pdsRepository->postRideToTimeline($node);
    }

    /**
     * Implements hook_node_update().
     *
     * @param \Drupal\node\NodeInterface $node
     *   The node being updated.
     */
    #[Hook('node_update')]
    public function onRideUpdate(NodeInterface $node): void
    {
        if ($node->bundle() !== 'ride') {
            return;
        }

        $this->syncRideToPds($node);
    }

    /**
     * Synchronizes ride data to PDS.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The ride node.
     */
    public function syncRideToPds(NodeInterface $node): void
    {
        try {
            $result = $this->pdsRepository->syncRide($node);

            if ($result) {
                $this->logger->info("Ride data synced to PDS for node @id.", ['@id' => $node->id()]);
            }
            else {
                $this->logger->warning("Local ride @id saved, but PDS data sync failed.", ['@id' => $node->id()]);
            }
        }
        catch (\Exception $e) {
            $this->logger->critical("PDS Hook crashed: " . $e->getMessage());
        }
    }

    /**
     * Implements hook_node_delete().
     *
     * @param \Drupal\node\NodeInterface $node
     *   The node being deleted.
     */
    #[Hook('node_delete')]
    public function deleteRideCleanup(NodeInterface $node): void
    {
        if ($node->bundle() === 'ride') {
            // Cleanup the state entry when the node is gone.
            $this->state->delete('pds_sync.sync.' . $node->uuid());
        }
    }

    /**
     * Implements hook_entity_base_field_info().
     *
     * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
     *   The entity type definition.
     *
     * @return array|null
     *   An array of base field definitions, or NULL if not applicable.
     */
    #[Hook('entity_base_field_info')]
    public function entityBaseFieldInfo(EntityTypeInterface $entity_type): ?array
    {
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

        return NULL;
    }

    /**
     * Implements hook_cron().
     */
    #[Hook('cron')]
    public function cron(): void
    {
        $syndications = $this->pdsRepository->getSyndications();

        foreach ($syndications as $syndication) {
            if (isset($syndication['at_uri'])) {
                $this->logger->info("Checking syndication of node @nid for webmentions.", ['@nid' => $syndication['nid']]);
                $this->pdsRepository->checkForWebmentions($syndication);
            }
        }
    }

}

