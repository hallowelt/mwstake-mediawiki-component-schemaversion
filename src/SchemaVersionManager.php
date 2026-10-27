<?php

namespace MWStake\MediaWiki\Component\SchemaVersion;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater;
use ReflectionException;
use Wikimedia\Rdbms\IMaintainableDatabase;

class SchemaVersionManager {

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct(
		private readonly HookContainer $hookContainer
	) {
	}

	/**
	 * @param IMaintainableDatabase $db
	 * @return void
	 */
	public function storeCurrentSchemaVersion( IMaintainableDatabase $db ) {
		$snapshot = $this->getSchemaSnapshot( $db );
		$timestamp = $db->timestamp();
		$db->newInsertQueryBuilder()
			->table( 'mws_schema_version' )
			->row( [
				'mwssv_mediawiki_version'	=> MW_VERSION,
				'mwssv_version_hash'		=> $snapshot['schemaVersionHash'],
				'mwssv_version_timestamp'   => $timestamp,
				'mwssv_version_blob'		=> json_encode(
												$snapshot,
												JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
											),
				'mwssv_wiki_id'				=> WikiMap::getCurrentWikiId()
			] )
			->caller( __METHOD__ )
			->execute();

		$db->newDeleteQueryBuilder()
			->table( 'mws_schema_version' )
			->where( [
				$db->expr( 'mwssv_version_timestamp', '!=', $timestamp ),
				'mwssv_wiki_id' => WikiMap::getCurrentWikiId()
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param IMaintainableDatabase $db
	 * @param string $targetHash
	 * @return array wiki_id => schema_hash array
	 */
	public function getPendingWikiIds( IMaintainableDatabase $db, string $targetHash ): array {
		$res = $db->newSelectQueryBuilder()
			->from( 'mws_schema_version' )
			->select( [ 'mwssv_version_hash', 'mwssv_wiki_id' ] )
			->where( [ $db->expr( 'mwssv_version_hash', '!=', $targetHash ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$hashes = [];
		foreach ( $res as $row ) {
			$hashes[$row->mwssv_wiki_id] = $row->mwssv_version_hash;
		}
		return $hashes;
	}

	/**
	 * @param IMaintainableDatabase $db
	 * @return array|null
	 */
	public function getCurrentVersion( IMaintainableDatabase $db ): ?array {
		if ( !$db->tableExists( 'mws_schema_version', __METHOD__ ) ) {
			return null;
		}

		$row = $db->newSelectQueryBuilder()
			->from( 'mws_schema_version' )
			->select( [
				'mwssv_mediawiki_version', 'mwssv_version_hash', 'mwssv_version_timestamp',
				'mwssv_version_blob', 'mwssv_wiki_id'
			] )
			->where( [ 'mwssv_wiki_id' => WikiMap::getCurrentWikiId() ] )
			->limit( 1 )
			->caller( __METHOD__ )
			// Should never have more than one row, but just in case...
			->orderBy( [ 'mwssv_version_timestamp' ], 'DESC' )
			->fetchRow();

		if ( !$row ) {
			return null;
		}

		return [
			'mw' => $row->mwssv_mediawiki_version,
			'timestamp' => $row->mwssv_version_timestamp,
			'hash' => $row->mwssv_version_hash,
			'blob' => json_decode( (string)$row->mwssv_version_blob, true ),
			'wiki_id' => $row->mwssv_wiki_id,
		];
	}

	/**
	 * @param IMaintainableDatabase $db
	 * @param bool $shared
	 * @param Maintenance|null $maintenance
	 * @return array
	 * @throws ReflectionException
	 */
	public function getSchemaSnapshot(
		IMaintainableDatabase $db, bool $shared = false, ?Maintenance $maintenance = null
	): array {
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
