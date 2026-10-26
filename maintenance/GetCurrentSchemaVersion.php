<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater;
use Wikimedia\Rdbms\IMaintainableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( !$IP ) {
	$IP = dirname( __DIR__, 4 );
}

require_once $IP . '/maintenance/Maintenance.php';
require_once $IP . '/includes/Installer/DatabaseUpdater.php';
require_once dirname( __DIR__ ) . '/src/Updater/CollectingUpdater.php';

class GetCurrentSchemaVersion extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Calculates schema version hash from registered LoadExtensionSchemaUpdates definitions.'
		);
		$this->addOption(
			'pretty',
			'Pretty-print JSON output'
		);
		$this->addOption(
			'include-updates',
			'Include normalized update list in output'
		);
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancerFactory()->getPrimaryDatabase();
		if ( !( $db instanceof IMaintainableDatabase ) ) {
			$this->fatalError( 'Primary DB connection is not maintainable' );
		}

		$updater = new CollectingUpdater( $db, false, $this );
		$updater->collectFromHookContainer( $services->getHookContainer() );
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
			'schemaVersionHash' => $this->calculateHash( $normalizedUpdates ),
		];

		if ( $this->hasOption( 'include-updates' ) ) {
			$payload['updates'] = $normalizedUpdates;
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $this->hasOption( 'pretty' ) ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		$json = json_encode( $payload, $flags );
		if ( $json === false ) {
			$this->fatalError( 'Failed to encode schema version payload' );
		}

		$this->output( $json . "\n" );
	}

	/**
	 * @param array[] $normalizedUpdates
	 * @return string
	 */
	private function calculateHash( array $normalizedUpdates ): string {
		$json = json_encode( $normalizedUpdates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json === false ) {
			$this->fatalError( 'Failed to encode normalized updates for hashing' );
		}

		return hash( 'sha256', $json );
	}
}

$maintClass = GetCurrentSchemaVersion::class;
require_once RUN_MAINTENANCE_IF_MAIN;
