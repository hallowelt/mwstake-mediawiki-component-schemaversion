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

class GetPendingWikis extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Compares current updater-derived snapshot against the stored schema_version DB snapshot.'
		);
		$this->addOption(
			'compare-hash',
			'Hash to compare against',
			required: true,
			withArg: true
		);
		$this->addOption(
			'exclude-wikis',
			'Comma-separated list of wikis to exclude from the comparison',
			withArg: true
		);
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancerFactory()->getPrimaryDatabase();
		if ( !( $db instanceof IMaintainableDatabase ) ) {
			$this->fatalError( 'Primary DB connection is not maintainable' );
		}

		$manager = new SchemaVersionManager( $services->getHookContainer() );
		$targetHash = $this->getOption( 'compare-hash' );
		$excludeWikis = explode( ',', $this->getOption( 'exclude-wikis' ) );
		$excludeWikis = array_map( 'trim', $excludeWikis );

		$pending = $manager->getPendingWikiIds( $db, $targetHash );
		foreach ( $excludeWikis as $wiki ) {
			if ( isset( $pending[$wiki] ) ) {
				unset( $pending[$wiki] );
			}
		}
		$this->output( json_encode( $pending ) );
	}
}

$maintClass = GetPendingWikis::class;
require_once RUN_MAINTENANCE_IF_MAIN;
