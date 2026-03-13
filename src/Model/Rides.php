<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Model;

/**
 *
 */

class Rides{

  protected $rides = [];
  // protected $date_formatter = \Drupal::service('date.formatter');
	
  public function __construct($records) {

	// Sort descending (newest first)
    usort($records, function ($a, $b) {
        return strcmp($b->value->date, $a->value->date);
    });

    foreach ($records as $ride) {
      	$this->rides[] = $this->parseRide($ride);      
    }
  }

  /**
   * GetFeed.
   */
  public function getRides() {
    return $this->rides;
  }

  /**
   * Parse post.
   *
   * Return array.
   */
  private function parseRide($ride) {

	return [
		'route' => $ride->value->route,
		'date'  => $ride->value->date,
		'miles' => $ride->value->miles, 
		'bike'  => $ride->value->bike,
		'body'  => $ride->value->body,
	];
  }

  

  // End of class.
}
