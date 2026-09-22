<?php
/**
 * Transport contract for KURABU API requests.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Transport;

use Kurabu\WPSync\Sync\ApiException;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one request and returns the answer.
 *
 * Everything that actually speaks HTTP lives behind this interface, so the
 * client, the handlers and the engine can be exercised without network access.
 */
interface Transport {

	/**
	 * Performs a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Request body, or null.
	 *
	 * @throws ApiException When no answer could be obtained at all.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): Response;
}
