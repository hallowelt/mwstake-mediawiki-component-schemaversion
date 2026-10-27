<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Maintenance;

use Maintenance;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\SchemaVersion\SchemaVersionManager;
use Throwable;
use Wikimedia\Rdbms\IMaintainableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( !$IP ) {
	$IP = dirname( __DIR__, 4 );
}

require_once $IP . '/maintenance/Maintenance.php';

class SchemaVersion extends Maintenance {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Provide schema version information for the primary database'
		);

		$this->addOption( 'stored', 'Show stored schema version' );
		$this->addOption( 'current', 'Show current schema version' );
	}

	public function execute() {
		$db = $this->getServiceContainer()->getDBLoadBalancerFactory()->getPrimaryDatabase();
		if ( !( $db instanceof IMaintainableDatabase ) ) {
			$this->fatalError( 'Primary DB connection is not maintainable' );
		}
		if ( $this->hasOption( 'stored' ) && $this->hasOption( 'current' ) ) {
			$this->fatalError( 'Cannot show both stored and current schema version' );
		}

		try {
			$manager = new SchemaVersionManager( $this->getServiceContainer()->getHookContainer() );
			if ( $this->hasOption( 'stored' ) ) {
				$version = $manager->getCurrentVersion( $db );
				if ( !$version ) {
					$this->output( json_encode( [
						'type' => 'none',
						'hash' => null,
						'timestamp' => null,
						'wiki_id' => WikiMap::getCurrentWikiId()
					] ) );
					return;
				}
				$this->output( json_encode( [
					'type' => 'stored',
					'hash' => $version['hash'],
					'timestamp' => $version['timestamp'],
					'wiki_id' => $version['wiki_id']
				] ) );
			} else {
				$snapshot = $manager->getSchemaSnapshot( $db );
				if ( !$snapshot ) {
					$this->fatalError( 'Cannot get schema snapshot' );
				}
				$this->output( json_encode( [
					'type' => 'current',
					'hash' => $snapshot['schemaVersionHash'],
					'timestamp' => MWTimestamp::now(),
					'wiki_id' => WikiMap::getCurrentWikiId(),
				] ) );
			}
		} catch ( Throwable $e ) {
			$this->fatalError( $e->getMessage() );
		}
	}
}

$maintClass = SchemaVersion::class;
require_once RUN_MAINTENANCE_IF_MAIN;
