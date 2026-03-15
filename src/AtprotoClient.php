<?php

declare(strict_types=1);

namespace Drupal\pds_sync;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
Use Drupal\Core\TempStore\PrivateTempStoreFactory;
use GuzzleHttp\ClientInterface;

use Drupal\pds_sync\EndPoints;

/**
 * The pds_sync class implements the core services of this module
 *
 */
class AtprotoClient {

    /**
     * @var 
     *
     */
	protected $settings; // Immutable settings snapshot
	protected ?string $did;
	protected ?string $handle;
	protected $session = null;
	protected $tempstore;
	protected string $pdsUrl = "https://banjo.paullieberman.org";
	
	public function __construct(
		protected LoggerChannelInterface   $logger,
		protected ConfigFactoryInterface   $configFactory,
		protected KeyRepositoryInterface   $keyRepository,
		protected ClientInterface          $httpClient,
		PrivateTempStoreFactory            $tempStore,
		protected EndPoints                $endpoints
		) {
		
			$this->tempstore = $tempStore->get('pds_sync');
			
			// Use the Factory to get our immutable settings
			$this->settings = $this->configFactory->get('pds_sync.settings');
			
			$this->handle = $this->settings->get('handle');
			$this->did    = $this->settings->get('did');
			
			// Lazy-resolve DID if missing
			if (empty($this->did) && !empty($this->handle)) {
			$this->did = $this->getDidForHandle($this->handle);
			if ($this->did) {
				$this->saveDid($this->did);
			}
		}
	}

	protected function getSession() {
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
	 */
	public function request(string $method, string $endpoint, array $options = []) {
		$session = $this->getSession();
		
		if (!$session) {
			$this->logger->error("Could not establish a session for $endpoint");
			return false;
		}
	
		$default_options = [
			'timeout'         => 5, // Total request time
			'connect_timeout' => 2, // Time to establish connection
			'headers' => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => "Bearer " . $session->accessJwt,
			],
		];
	
		$options = array_merge_recursive($default_options, $options);
	
		try {
			$response = $this->httpClient->request($method, $this->pdsUrl . $endpoint, $options);
			
			if ($response->getStatusCode() === 200) {
				return json_decode($response->getBody()->getContents());
			}
		} catch (\Exception $e) {
			$this->logger->error("atproto request to $endpoint failed: " . $e->getMessage());
		}
	
		return false;
    }
	
	public function getHandle(){
		return $this->handle;
	}
	
	public function getDid(){
		return $this->did;
	}
	

   /**
     * Logout
     *
     * Delete the saved session
     */
    public function logout()
    {
        $this->tempstore->delete('session');
        $this->logger->info("Session closed");
    }

   

	/*************** Private functions *******************/
	private function saveDid(string $did) {
 		 $this->configFactory->getEditable('pds_sync.settings')
    		->set('did', $did)
    		->save();
    }

    /**
     * Create authenicated sessionm
     *
     * Returns session data array
     *        did => string
     *        didDoc => array
     *        handle => string
     *        email => string
     *        emailConfirmed
     *        emailAuthFactor
     *        accessJwt => string
     *        refreshJwt => string
     *        active => boolean true
     */
    private function createSession($user, $pass)
    {

        $request = $this->httpClient->post(
            $this->pdsUrl . $this->endpoints->createSession(),
            [
            'headers' => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json'
            ],
            'body'  => json_encode(['identifier' => $user, 'password'   => $pass]),
            ]
        );

        if ($request->getStatusCode() == 200) {
            $this->logger->info("Session opened");
            return json_decode($request->getBody()->getContents());
        }
        $this->logger->error("Create session got " . $request->getStatusCode());
        return false;
    }

    private function refreshSession($session)
    {
        $request = $this->httpClient->post(
            $this->pdsUrl . $this->endpoints->refreshSession(),
            [
            'headers' => [
            'Authorization' => "Bearer " . $session->refreshJwt
            ],
            ]
        );

        if ($request->getStatusCode() == 200) {
            $this->logger->info("Session refreshed");
            return json_decode($request->getBody()->getContents());
        }
        $this->logger->error("Refresh session got " . $request->getStatusCode());
        return false;
    }

    /**
     * getDid
     *
     * Gets DID for Handle
     */
    public function getDidForHandle($handle)
    {
		if (empty($handle)) {
     	   return FALSE;
    	}
        $request = $this->httpClient->request(
            'GET',
            "https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile", [
            'query' => [
            'actor' => $handle,
            ],
            ]
        );

        if ($request->getStatusCode() == 200) {
            $profile = json_decode($request->getBody()->getContents());
            return($profile->did);
        }
        return FLASE;
    }


    /**
     * getPds for DID
     *
     * Uses plc.directory
     */
    private function getPds($did)
    {
        $request = $this->httpClient->request('GET', "https://plc.directory/" . $did);
        if ($request->getStatusCode() == 200) {
            $results = json_decode($request->getBody()->getContents());
            return $results->service[0]->serviceEndpoint;
        }
        return FLASE;
    }

    // End of class
}
