<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\SchemaVersion\SchemaVersionManager;
use Wikimedia\Rdbms\IMaintainableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( !$IP ) {
	$IP = dirname( __DIR__, 4 );
}

require_once $IP . '/maintenance/Maintenance.php';
require_once dirname( __DIR__ ) . '/src/SchemaVersionManager.php';

class ShouldUpgrade extends Maintenance {
	/** @var array<string, bool> */
	private const UPDATE_SCRIPT_CALLBACKS = [
		'addPostDatabaseUpdateMaintenance' => true,
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Compares current updater-derived snapshot against the stored schema_version DB snapshot.'
		);
		$this->addOption(
			'pretty',
			'Pretty-print JSON output'
		);
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancerFactory()->getPrimaryDatabase();
		if ( !( $db instanceof IMaintainableDatabase ) ) {
			$this->fatalError( 'Primary DB connection is not maintainable' );
		}

		$manager = new SchemaVersionManager( $services->getHookContainer() );
		$storedVersion = $manager->getCurrentVersion( $db );
		$currentSnapshot = $manager->getSchemaSnapshot( $db, false, $this );

		$comparison = $this->compareSnapshots(
			is_array( $storedVersion['blob'] ?? null ) ? $storedVersion['blob'] : null,
			$currentSnapshot,
			is_string( $storedVersion['hash'] ?? null ) ? $storedVersion['hash'] : null,
			is_string( $storedVersion['timestamp'] ?? null ) ? $storedVersion['timestamp'] : null,
			is_string( $storedVersion['mw'] ?? null ) ? $storedVersion['mw'] : null
		);

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $this->hasOption( 'pretty' ) ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		$json = json_encode( $comparison, $flags );
		if ( $json === false ) {
			$this->fatalError( 'Failed to encode schema comparison result' );
		}

		$this->output( $json . "\n" );
	}

	/**
	 * @param array|null $storedSnapshot
	 * @param array $currentSnapshot
	 * @param string|null $storedHash
	 * @param string|null $storedTimestamp
	 * @param string|null $storedMwVersion
	 * @return array
	 */
	private function compareSnapshots(
		?array $storedSnapshot,
		array $currentSnapshot,
		?string $storedHash,
		?string $storedTimestamp,
		?string $storedMwVersion
	): array {
		if ( !$storedSnapshot ) {
			return [
				'comparisonFormatVersion' => 1,
				'hasStoredSnapshot' => false,
				'shouldUpgrade' => true,
				'isChanged' => true,
				'schemaChange' => true,
				'updateScriptChange' => false,
				'stored' => [
					'mediaWikiVersion' => $storedMwVersion,
					'timestamp' => $storedTimestamp,
					'hash' => $storedHash,
				],
				'currentSchemaVersionHash' => $currentSnapshot['schemaVersionHash'] ?? null,
				'reason' => 'No stored schema snapshot found in schema_version table',
			];
		}

		$previousUpdates = $this->normalizeSnapshotUpdateRows( $storedSnapshot['updates'] ?? [] );
		$currentUpdates = $this->normalizeSnapshotUpdateRows( $currentSnapshot['updates'] ?? [] );

		$previousById = [];
		foreach ( $previousUpdates as $row ) {
			$previousById[$row['id']] = $row;
		}
		$currentById = [];
		foreach ( $currentUpdates as $row ) {
			$currentById[$row['id']] = $row;
		}

		$addedIds = array_values( array_diff( array_keys( $currentById ), array_keys( $previousById ) ) );
		$removedIds = array_values( array_diff( array_keys( $previousById ), array_keys( $currentById ) ) );
		sort( $addedIds );
		sort( $removedIds );

		$modifiedUpdates = $this->detectModifiedUpdates( $previousUpdates, $currentUpdates );
		$changeFlags = $this->calculateChangeFlags(
			$addedIds,
			$removedIds,
			$modifiedUpdates,
			$previousById,
			$currentById
		);

		$previousHash = is_string( $storedSnapshot['schemaVersionHash'] ?? null )
			? $storedSnapshot['schemaVersionHash']
			: $storedHash;
		$currentHash = is_string( $currentSnapshot['schemaVersionHash'] ?? null )
			? $currentSnapshot['schemaVersionHash']
			: null;

		$isChanged = $previousHash !== $currentHash
			|| $addedIds !== []
			|| $removedIds !== []
			|| $modifiedUpdates !== [];

		return [
			'comparisonFormatVersion' => 1,
			'hasStoredSnapshot' => true,
			'shouldUpgrade' => $isChanged,
			'isChanged' => $isChanged,
			'schemaChange' => $changeFlags['schemaChange'],
			'updateScriptChange' => $changeFlags['updateScriptChange'],
			'stored' => [
				'mediaWikiVersion' => $storedMwVersion,
				'timestamp' => $storedTimestamp,
				'hash' => $storedHash,
			],
			'previousSchemaVersionHash' => $previousHash,
			'currentSchemaVersionHash' => $currentHash,
			'previousUpdateCount' => count( $previousUpdates ),
			'currentUpdateCount' => count( $currentUpdates ),
			'delta' => [
				'added' => count( $addedIds ),
				'removed' => count( $removedIds ),
				'modified' => count( $modifiedUpdates ),
			],
			'schemaStats' => [
				'previous' => $storedSnapshot['schemaStats'] ?? null,
				'current' => $currentSnapshot['schemaStats'] ?? null,
				'delta' => $this->buildStatsDelta(
					is_array( $storedSnapshot['schemaStats'] ?? null ) ? $storedSnapshot['schemaStats'] : [],
					is_array( $currentSnapshot['schemaStats'] ?? null ) ? $currentSnapshot['schemaStats'] : []
				),
			],
			'addedUpdateIds' => $addedIds,
			'removedUpdateIds' => $removedIds,
			'modifiedUpdates' => $modifiedUpdates,
		];
	}

	/**
	 * @param string[] $addedIds
	 * @param string[] $removedIds
	 * @param array[] $modifiedUpdates
	 * @param array<string, array> $previousById
	 * @param array<string, array> $currentById
	 * @return array{schemaChange: bool, updateScriptChange: bool}
	 */
	private function calculateChangeFlags(
		array $addedIds,
		array $removedIds,
		array $modifiedUpdates,
		array $previousById,
		array $currentById
	): array {
		$schemaChange = false;
		$updateScriptChange = false;

		foreach ( $addedIds as $id ) {
			$row = $currentById[$id] ?? null;
			if ( !is_array( $row ) ) {
				continue;
			}
			if ( $this->isUpdateScriptRow( $row ) ) {
				$updateScriptChange = true;
			} else {
				$schemaChange = true;
			}
		}

		foreach ( $removedIds as $id ) {
			$row = $previousById[$id] ?? null;
			if ( !is_array( $row ) ) {
				continue;
			}
			if ( $this->isUpdateScriptRow( $row ) ) {
				$updateScriptChange = true;
			} else {
				$schemaChange = true;
			}
		}

		foreach ( $modifiedUpdates as $modified ) {
			$callback = isset( $modified['callback'] ) && is_string( $modified['callback'] )
				? $modified['callback']
				: '';
			if ( isset( self::UPDATE_SCRIPT_CALLBACKS[$callback] ) ) {
				$updateScriptChange = true;
			} else {
				$schemaChange = true;
			}
		}

		return [
			'schemaChange' => $schemaChange,
			'updateScriptChange' => $updateScriptChange,
		];
	}

	/**
	 * @param array $updates
	 * @return array[]
	 */
	private function normalizeSnapshotUpdateRows( array $updates ): array {
		$rows = [];
		foreach ( $updates as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) && is_string( $row['id'] ) ? $row['id'] : null;
			if ( $id === null || $id === '' ) {
				continue;
			}
			$rows[] = [
				'id' => $id,
				'source' => isset( $row['source'] ) && is_string( $row['source'] ) ? $row['source'] : null,
				'virtualDomain' => isset( $row['virtualDomain'] ) && is_string( $row['virtualDomain'] )
					? $row['virtualDomain']
					: null,
				'callback' => isset( $row['callback'] ) && is_string( $row['callback'] ) ? $row['callback'] : null,
				'targetFilePath' => isset( $row['targetFilePath'] ) && is_string( $row['targetFilePath'] )
					? $row['targetFilePath']
					: null,
				'targetFileHash' => isset( $row['targetFileHash'] ) && is_string( $row['targetFileHash'] )
					? $row['targetFileHash']
					: null,
				'paramsHash' => isset( $row['paramsHash'] ) && is_string( $row['paramsHash'] ) ? $row['paramsHash'] : null,
			];
		}

		usort( $rows, static function ( array $left, array $right ): int {
			return strcmp( $left['id'], $right['id'] );
		} );

		return $rows;
	}

	/**
	 * @param array[] $previousUpdates
	 * @param array[] $currentUpdates
	 * @return array[]
	 */
	private function detectModifiedUpdates( array $previousUpdates, array $currentUpdates ): array {
		$previousByLogicalKey = [];
		foreach ( $previousUpdates as $row ) {
			$previousByLogicalKey[$this->buildLogicalKey( $row )][] = $row;
		}
		$currentByLogicalKey = [];
		foreach ( $currentUpdates as $row ) {
			$currentByLogicalKey[$this->buildLogicalKey( $row )][] = $row;
		}

		$modified = [];
		$logicalKeys = array_unique( array_merge(
			array_keys( $previousByLogicalKey ),
			array_keys( $currentByLogicalKey )
		) );
		sort( $logicalKeys );

		foreach ( $logicalKeys as $logicalKey ) {
			$previousRows = $previousByLogicalKey[$logicalKey] ?? [];
			$currentRows = $currentByLogicalKey[$logicalKey] ?? [];
			if ( $previousRows === [] || $currentRows === [] ) {
				continue;
			}

			$previousById = [];
			foreach ( $previousRows as $row ) {
				$previousById[$row['id']] = $row;
			}
			$currentById = [];
			foreach ( $currentRows as $row ) {
				$currentById[$row['id']] = $row;
			}

			foreach ( $currentRows as $currentRow ) {
				if ( isset( $previousById[$currentRow['id']] ) ) {
					continue;
				}

				$before = null;
				foreach ( $previousRows as $candidate ) {
					if ( !isset( $currentById[$candidate['id']] ) ) {
						$before = $candidate;
						break;
					}
				}
				if ( $before === null ) {
					continue;
				}

				$modified[] = [
					'logicalKey' => $logicalKey,
					'beforeId' => $before['id'],
					'afterId' => $currentRow['id'],
					'targetFilePath' => $currentRow['targetFilePath'] ?? $before['targetFilePath'],
					'beforeTargetFileHash' => $before['targetFileHash'],
					'afterTargetFileHash' => $currentRow['targetFileHash'],
					'callback' => $currentRow['callback'],
				];
			}
		}

		usort( $modified, static function ( array $left, array $right ): int {
			$leftKey = $left['logicalKey'] . '|' . $left['afterId'];
			$rightKey = $right['logicalKey'] . '|' . $right['afterId'];
			return strcmp( $leftKey, $rightKey );
		} );

		return $modified;
	}

	/**
	 * @param array $row
	 * @return string
	 */
	private function buildLogicalKey( array $row ): string {
		$source = (string)( $row['source'] ?? '' );
		$virtualDomain = (string)( $row['virtualDomain'] ?? '' );
		$callback = (string)( $row['callback'] ?? '' );
		$paramsHash = (string)( $row['paramsHash'] ?? '' );

		return implode( '|', [ $source, $virtualDomain, $callback, $paramsHash ] );
	}

	/**
	 * @param array $row
	 * @return bool
	 */
	private function isUpdateScriptRow( array $row ): bool {
		$callback = isset( $row['callback'] ) && is_string( $row['callback'] ) ? $row['callback'] : '';
		return isset( self::UPDATE_SCRIPT_CALLBACKS[$callback] );
	}

	/**
	 * @param array $previous
	 * @param array $current
	 * @return array
	 */
	private function buildStatsDelta( array $previous, array $current ): array {
		$getInt = static function ( array $arr, array $path ): int {
			$value = $arr;
			foreach ( $path as $key ) {
				if ( !is_array( $value ) || !array_key_exists( $key, $value ) ) {
					return 0;
				}
				$value = $value[$key];
			}
			return is_int( $value ) ? $value : 0;
		};

		return [
			'tableOperations' => [
				'added' => $getInt( $current, [ 'tableOperations', 'added' ] ) -
					$getInt( $previous, [ 'tableOperations', 'added' ] ),
				'removed' => $getInt( $current, [ 'tableOperations', 'removed' ] ) -
					$getInt( $previous, [ 'tableOperations', 'removed' ] ),
				'changed' => $getInt( $current, [ 'tableOperations', 'changed' ] ) -
					$getInt( $previous, [ 'tableOperations', 'changed' ] ),
			],
			'uniqueTables' => [
				'added' => $getInt( $current, [ 'uniqueTables', 'added' ] ) -
					$getInt( $previous, [ 'uniqueTables', 'added' ] ),
				'removed' => $getInt( $current, [ 'uniqueTables', 'removed' ] ) -
					$getInt( $previous, [ 'uniqueTables', 'removed' ] ),
				'changed' => $getInt( $current, [ 'uniqueTables', 'changed' ] ) -
					$getInt( $previous, [ 'uniqueTables', 'changed' ] ),
			],
			'otherUpdates' => [
				'count' => $getInt( $current, [ 'otherUpdates', 'count' ] ) -
					$getInt( $previous, [ 'otherUpdates', 'count' ] ),
			],
		];
	}
}

$maintClass = ShouldUpgrade::class;
require_once RUN_MAINTENANCE_IF_MAIN;
