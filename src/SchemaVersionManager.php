<?php

namespace MWStake\MediaWiki\Component\SchemaVersion;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Maintenance\Maintenance;
use MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater;
use Wikimedia\Rdbms\IMaintainableDatabase;

class SchemaVersionManager {

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct(
		private readonly HookContainer $hookContainer
	) {}

	/**
	 * @param IMaintainableDatabase $db
	 * @return void
	 */
	public function storeCurrentSchemaVersion( IMaintainableDatabase $db ) {
		$snapshot = $this->getSchemaSnapshot( $db );
		$timestamp = $db->timestamp();
		$db->newInsertQueryBuilder()
			->table( 'schema_version' )
			->row( [
				'sv_mediawiki_version'	=> MW_VERSION,
				'sv_version_hash'		=> $snapshot['schemaVersionHash'],
				'sv_version_timestamp'  => $timestamp,
				'sv_version_blob'		=> json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			] )
			->caller( __METHOD__ )
			->execute();

		$db->newDeleteQueryBuilder()
			->table( 'schema_version' )
			->where( $db->expr( 'sv_version_timestamp', '!=', $timestamp ) )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param IMaintainableDatabase $db
	 * @return array|null
	 */
	public function getCurrentVersion( IMaintainableDatabase $db ): ?array {
		if ( !$db->tableExists( 'schema_version', __METHOD__ ) ) {
			return null;
		}

		$row = $db->newSelectQueryBuilder()
			->from( 'schema_version' )
			->select( [ 'sv_mediawiki_version', 'sv_version_hash', 'sv_version_timestamp', 'sv_version_blob' ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			return null;
		}

		$getField = static function ( $r, string $field ) {
			if ( is_array( $r ) ) {
				return $r[$field] ?? null;
			}
			if ( is_object( $r ) ) {
				return $r->$field ?? null;
			}
			return null;
		};

		return [
			'mw' => $getField( $row, 'sv_mediawiki_version' ),
			'timestamp' => $getField( $row, 'sv_version_timestamp' ),
			'hash' => $getField( $row, 'sv_version_hash' ),
			'blob' => json_decode( (string)$getField( $row, 'sv_version_blob' ), true ),
		];
	}

	public function getSchemaSnapshot( IMaintainableDatabase $db, bool $shared = false, ?Maintenance $maintenance = null ): array {
		$updater = new CollectingUpdater( $db, $shared, $maintenance );
		$updater->collectFromHookContainer( $this->hookContainer );
		$updater->collectCoreUpdates();

		$coreUpdates = $updater->getNormalizedCoreUpdates();
		$extensionUpdates = $updater->getNormalizedUpdates();
		$normalizedUpdates = $updater->getNormalizedAllUpdates();
		$payload = [
			'formatVersion' => 1,
			'dbType' => $db->getType(),
			'coreUpdateCount' => count( $coreUpdates ),
			'extensionUpdateCount' => count( $extensionUpdates ),
			'updateCount' => count( $normalizedUpdates ),
			'schemaStats' => $this->calculateSchemaStats( $normalizedUpdates ),
			'schemaVersionHash' => $this->calculateHash( $normalizedUpdates ),
		];

		return $this->buildSnapshotPayload( $payload, $normalizedUpdates );
	}

	/**
	 * @param array[] $normalizedUpdates
	 * @return string
	 * @throws MaintenanceFatalError
	 */
	private function calculateHash( array $normalizedUpdates ): string {
		$json = json_encode( $normalizedUpdates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json === false ) {
			throw new \RuntimeException( 'Failed to encode normalized updates for hashing' );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * @param array[] $normalizedUpdates
	 * @return array
	 */
	private function calculateSchemaStats( array $normalizedUpdates ): array {
		$addTableCallbacks = [ 'addTable' => true ];
		$removeTableCallbacks = [ 'dropTable' => true ];
		$changeTableCallbacks = [
			'addField' => true,
			'dropField' => true,
			'modifyField' => true,
			'modifyTable' => true,
			'modifyPrimaryKey' => true,
		];

		$addedTableOps = 0;
		$removedTableOps = 0;
		$changedTableOps = 0;
		$otherUpdates = 0;
		$otherByCallback = [];

		$addedTables = [];
		$removedTables = [];
		$changedTables = [];

		foreach ( $normalizedUpdates as $update ) {
			$callback = isset( $update['callback'] ) && is_string( $update['callback'] )
				? $update['callback']
				: '';
			$table = $this->extractTableName( $update );

			if ( isset( $addTableCallbacks[$callback] ) ) {
				$addedTableOps++;
				if ( $table !== null ) {
					$addedTables[$table] = true;
				}
				continue;
			}

			if ( isset( $removeTableCallbacks[$callback] ) ) {
				$removedTableOps++;
				if ( $table !== null ) {
					$removedTables[$table] = true;
				}
				continue;
			}

			if ( isset( $changeTableCallbacks[$callback] ) ) {
				$changedTableOps++;
				if ( $table !== null ) {
					$changedTables[$table] = true;
				}
				continue;
			}

			$otherUpdates++;
			if ( !isset( $otherByCallback[$callback] ) ) {
				$otherByCallback[$callback] = 0;
			}
			$otherByCallback[$callback]++;
		}

		ksort( $otherByCallback );

		return [
			'tableOperations' => [
				'added' => $addedTableOps,
				'removed' => $removedTableOps,
				'changed' => $changedTableOps,
			],
			'uniqueTables' => [
				'added' => count( $addedTables ),
				'removed' => count( $removedTables ),
				'changed' => count( $changedTables ),
			],
			'otherUpdates' => [
				'count' => $otherUpdates,
				'byCallback' => $otherByCallback,
			],
		];
	}

	/**
	 * @param array $update
	 * @return string|null
	 */
	private function extractTableName( array $update ): ?string {
		if ( !isset( $update['params'] ) || !is_array( $update['params'] ) ) {
			return null;
		}

		$table = $update['params'][0] ?? null;
		return is_string( $table ) && $table !== '' ? $table : null;
	}

	/**
	 * @param array $payload
	 * @param array[] $normalizedUpdates
	 * @return array
	 */
	private function buildSnapshotPayload( array $payload, array $normalizedUpdates ): array {
		$updates = [];
		foreach ( $normalizedUpdates as $update ) {
			$updateJson = json_encode( $update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $updateJson === false ) {
				throw new \RuntimeException( 'Failed to encode update while creating snapshot payload' );
			}

			$params = isset( $update['params'] ) && is_array( $update['params'] ) ? $update['params'] : [];
			$paramsJson = json_encode( $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $paramsJson === false ) {
				throw new \RuntimeException( 'Failed to encode update params while creating snapshot payload' );
			}

			$targetFilePath = null;
			$targetFileHash = null;
			if ( isset( $update['targetFile'] ) && is_array( $update['targetFile'] ) ) {
				$targetFilePath = isset( $update['targetFile']['path'] ) && is_string( $update['targetFile']['path'] )
					? $update['targetFile']['path']
					: null;
				$targetFileHash = isset( $update['targetFile']['hash'] ) && is_string( $update['targetFile']['hash'] )
					? $update['targetFile']['hash']
					: null;
			}

			$updates[] = [
				'id' => hash( 'sha256', $updateJson ),
				'source' => isset( $update['source'] ) && is_string( $update['source'] ) ? $update['source'] : null,
				'virtualDomain' => isset( $update['virtualDomain'] ) && is_string( $update['virtualDomain'] )
					? $update['virtualDomain']
					: null,
				'callback' => isset( $update['callback'] ) && is_string( $update['callback'] )
					? $update['callback']
					: null,
				'targetFilePath' => $targetFilePath,
				'targetFileHash' => $targetFileHash,
				'paramsHash' => hash( 'sha256', $paramsJson ),
			];
		}

		return [
			'snapshotFormatVersion' => 1,
			'dbType' => $payload['dbType'],
			'schemaVersionHash' => $payload['schemaVersionHash'],
			'updateCount' => $payload['updateCount'],
			'schemaStats' => $payload['schemaStats'],
			'updates' => $updates,
		];
	}
}
