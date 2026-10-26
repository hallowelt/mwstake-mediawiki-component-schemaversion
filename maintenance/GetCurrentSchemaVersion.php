<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Maintenance;

use Maintenance;
use MWStake\MediaWiki\Component\SchemaVersion\SchemaVersionManager;
use Throwable;
use Wikimedia\Rdbms\IMaintainableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( !$IP ) {
	$IP = dirname( __DIR__, 4 );
}

require_once $IP . '/maintenance/Maintenance.php';
require_once $IP . '/includes/Installer/DatabaseUpdater.php';
require_once dirname( __DIR__ ) . '/src/Updater/CollectingUpdater.php';

class GetCurrentSchemaVersion extends Maintenance {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Provide a comparable JSON dump of the current schema version.'
		);
	}

	public function execute() {
		$db = $this->getServiceContainer()->getDBLoadBalancerFactory()->getPrimaryDatabase();
		if ( !( $db instanceof IMaintainableDatabase ) ) {
			$this->fatalError( 'Primary DB connection is not maintainable' );
		}

		try {
			$manager = new SchemaVersionManager( $this->getServiceContainer()->getHookContainer() );
			$snapshot = $manager->getSchemaSnapshot( $db, false, $this );

			$this->output( json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} catch ( Throwable $e ) {
			$this->fatalError( $e->getMessage() );
		}
	}
}

$maintClass = GetCurrentSchemaVersion::class;
require_once RUN_MAINTENANCE_IF_MAIN;
