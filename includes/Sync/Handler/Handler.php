<?php
/**
 * Contract for one resource handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Support\Logger;
use Kurabu\WPSync\Sync\Client;
use Kurabu\WPSync\Sync\ResourceResult;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs exactly one data kind.
 *
 * One handler per resource keeps a failure local: the engine catches the
 * exception of a single handler and the other resources still run.
 */
interface Handler {

	/**
	 * The resource key this handler is responsible for.
	 */
	public function resource(): string;

	/**
	 * Fetches and stores the resource.
	 *
	 * @param Client      $client The API client.
	 * @param Logger      $logger Logger of the current run.
	 * @param string|null $since  MySQL datetime of the last success, or null
	 *                            for a full run.
	 *
	 * @throws \Kurabu\WPSync\Sync\ApiException When the API call fails.
	 */
	public function sync( Client $client, Logger $logger, ?string $since ): ResourceResult;
}
