<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\atproto_client\Client\AtprotoClient;

/**
 * Provides a 'Hello World' Action.
 *
 * @Action(
 * id = "pds_sync_hello_world",
 * label = @Translation("Display a Hello World message"),
 * type = "node"
 * )
 */
final class HelloWorldAction extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AtprotoClient $atprotoClient // Your custom service
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('atproto_client.atproto_client') // Replace with your actual service ID
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    $this->messenger()->addMessage($this->t('Hello World! You saved the node: @title', [
      '@title' => $entity?->label() ?? 'Unknown',
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    // In Drupal 11, we should return an AccessResult object.
    $result = AccessResult::allowed();

    // If the caller explicitly asked for a boolean (the default), 
    // we return the result of isAllowed(). Otherwise, return the object.
    return $return_as_object ? $result : $result->isAllowed();
  }
  
}
