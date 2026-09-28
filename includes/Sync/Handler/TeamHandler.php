<?php
/**
 * Teams / Trainingsgruppen handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\TeamRepository;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Teams / Trainingsgruppen into the local cache.
 *
 * KURABU does not name a team's department on the team: a category lists its
 * teams in `team_ids`. The department is therefore read back out of the
 * categories that are already in the cache, which is why the departments run
 * before the teams.
 */
final class TeamHandler extends AbstractRepositoryHandler {

	/**
	 * Department per team KURABU id, built once per run.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $departments = null;

	public function resource(): string {
		return Resource::TEAMS;
	}

	protected function repository(): TeamRepository {
		return plugin()->teams();
	}

	/**
	 * Adds the department a team belongs to.
	 *
	 * @param array<string, mixed>     $data   Column values.
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare( array $data, array $record ): array {
		unset( $record );

		if ( '' !== (string) ( $data['department_kurabu_id'] ?? '' ) ) {
			return $data;
		}

		$team_id = (string) ( $data['kurabu_id'] ?? '' );
		$map     = $this->departments();

		$data['department_kurabu_id'] = $map[ $team_id ] ?? '';

		return $data;
	}

	/**
	 * The department of every team, read out of the cached categories.
	 *
	 * @return array<string, string>
	 */
	private function departments(): array {
		if ( null !== $this->departments ) {
			return $this->departments;
		}

		$map = array();

		foreach ( plugin()->departments()->query( array( 'orderby' => 'name' ) ) as $department ) {
			$team_ids = $department->payload()['team_ids'] ?? array();

			if ( ! is_array( $team_ids ) ) {
				continue;
			}

			foreach ( $team_ids as $team_id ) {
				if ( is_scalar( $team_id ) ) {
					// A team in two categories keeps the first one.
					$key = trim( (string) $team_id );

					if ( '' !== $key && ! isset( $map[ $key ] ) ) {
						$map[ $key ] = $department->kurabu_id();
					}
				}
			}
		}

		$this->departments = $map;

		return $map;
	}
}
