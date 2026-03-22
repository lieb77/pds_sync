<?php

declare(strict_types=1);

namespace Drupal\pds_sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * The AtprotoClient class implements the core services of this module.
 */
class AtprotoClient {

  /**
   * Immutable settings snapshot.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $settings;

  /**
   * The Decentralized Identifier.
   *
   * @var string|null
   */
  protected ?string $did;

  /**
   * The handle.
   *
   * @var string|null
   */
  protected ?string $handle;

  /**
   * The session data.
   *
   * @var object|null
   */
  protected ?object $session = NULL;

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected  $tempstore;

  /**
   * The PDS URL.
   *
   * @var string
   */
  protected string $pdsUrl = "https://banjo.paullieberman.org";

  /**
   * Constructs a new AtprotoClient object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStore
   *   The tempstore factory.
   * @param \Drupal\pds_sync\EndPoints $endpoints
   *   The endpoints service.
   */
  public function __construct(
    protected LoggerChannelInterface $logger,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected ClientInterface $httpClient,
    PrivateTempStoreFactory $tempStore,
    protected EndPoints $endpoints
  ) {
    $this->tempstore = $tempStore->get('pds_sync');

    // Use the Factory to get our immutable settings.
    $this->settings = $this->configFactory->get('pds_sync.settings');

    $this->handle = $this->settings->get('handle');
    $this->did = $this->settings->get('did');

    // Lazy-resolve DID if missing.
    if (empty($this->did) && !empty($this->handle)) {
      $this->did = $this->getDidForHandle($this->handle);
      if ($this->did) {
        $this->saveDid($this->did);
      }
    }
  }

  /**
   * Gets the session.
   *
   * @return object|false
   *   The session object or FALSE on failure.
   */
  protected function getSession(): object|false {
    if ($this->session) {
      return $this->session;
    }

    $appKey = $this->settings->get('app_key');
    if (empty($this->handle) || empty($appKey)) {
      return FALSE;
    }

    $session = $this->tempstore->get('session');

    if ($session) {
      $this->session = $this->refreshSession($session);
    }

    if (!$this->session) {
      $key = $this->keyRepository->getKey($appKey)->getKeyValue();
      $this->session = $this->createSession($this->handle, $key);
    }

    $this->tempstore->set('session', $this->session);
    return $this->session;
  }

  /**
   * Make an authenticated call to the PDS.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $endpoint
   *   The API endpoint.
   * @param array $options
   *   (optional) An array of request options.
   *
   * @return object|false
   *   The decoded JSON response or FALSE on failure.
   */
  public function request(string $method, string $endpoint, array $options = []): object|false {
    $session = $this->getSession();

    if (!$session) {
      $this->logger->error("Could not establish a session for $endpoint");
      return FALSE;
    }

    $default_options = [
      'timeout' => 5, // Total request time.
      'connect_timeout' => 2, // Time to establish connection.
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => "Bearer " . $session->accessJwt,
      ],
    ];

    $options = array_merge_recursive($default_options, $options);

    try {
      $response = $this->httpClient->request($method, $this->pdsUrl . $endpoint, $options);

      if ($response->getStatusCode() === 200) {
        return json_decode($response->getBody()->getContents());
      }
    }
    catch (\Exception $e) {
      $this->logger->error("atproto request to $endpoint failed: " . $e->getMessage());
    }

    return FALSE;
  }

  /**
   * Gets the handle.
   *
   * @return string|null
   *   The handle.
   */
  public function getHandle(): ?string {
    return $this->handle;
  }

  /**
   * Gets the DID.
   *
   * @return string|null
   *   The DID.
   */
  public function getDid(): ?string {
    return $this->did;
  }

  /**
   * Logout.
   *
   * Delete the saved session.
   */
  public function logout(): void {
    $this->tempstore->delete('session');
    $this->logger->info("Session closed");
  }

  /*************** Private functions *******************/

  /**
   * Saves the DID to configuration.
   *
   * @param string $did
   *   The DID to save.
   */
  private function saveDid(string $did): void {
    $this->configFactory->getEditable('pds_sync.settings')
      ->set('did', $did)
      ->save();
  }

  /**
   * Create authenicated session.
   *
   * Returns session data array.
   *   did => string
   *   didDoc => array
   *   handle => string
   *   email => string
   *   emailConfirmed
   *   emailAuthFactor
   *   accessJwt => string
   *   refreshJwt => string
   *   active => boolean true
   *
   * @param string $user
   *   The username.
   * @param string $pass
   *   The password.
   *
   * @return object|false
   *   The session object or FALSE on failure.
   */
  private function createSession(string $user, string $pass): object|false {
    $request = $this->httpClient->post(
      $this->pdsUrl . $this->endpoints->createSession(),
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'body' => json_encode(['identifier' => $user, 'password' => $pass]),
      ]
    );

    if ($request->getStatusCode() == 200) {
      $this->logger->info("Session opened");
      return json_decode($request->getBody()->getContents());
    }
    $this->logger->error("Create session got " . $request->getStatusCode());
    return FALSE;
  }

  /**
   * Refreshes the session.
   *
   * @param object $session
   *   The session object.
   *
   * @return object|false
   *   The refreshed session object or FALSE on failure.
   */
  private function refreshSession(object $session): object|false {
    $request = $this->httpClient->post(
      $this->pdsUrl . $this->endpoints->refreshSession(),
      [
        'headers' => [
          'Authorization' => "Bearer " . $session->refreshJwt,
        ],
      ]
    );

    if ($request->getStatusCode() == 200) {
      $this->logger->info("Session refreshed");
      return json_decode($request->getBody()->getContents());
    }
    $this->logger->error("Refresh session got " . $request->getStatusCode());
    return FALSE;
  }

  /**
   * Gets DID for Handle.
   *
   * @param string $handle
   *   The handle.
   *
   * @return string|false
   *   The DID or FALSE on failure.
   */
  public function getDidForHandle(string $handle): string|false {
    if (empty($handle)) {
      return FALSE;
    }
    $request = $this->httpClient->request(
      'GET',
      "https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile",
      [
        'query' => [
          'actor' => $handle,
        ],
      ]
    );

    if ($request->getStatusCode() == 200) {
      $profile = json_decode($request->getBody()->getContents());
      return $profile->did;
    }
    return FALSE;
  }

  /**
   * Gets PDS for DID.
   *
   * Uses plc.directory.
   *
   * @param string $did
   *   The DID.
   *
   * @return string|false
   *   The PDS URL or FALSE on failure.
   */
  private function getPds(string $did): string|false {
    $request = $this->httpClient->request('GET', "https://plc.directory/" . $did);
    if ($request->getStatusCode() == 200) {
      $results = json_decode($request->getBody()->getContents());
      return $results->service[0]->serviceEndpoint;
    }
    return FALSE;
  }

  // End of class
}

