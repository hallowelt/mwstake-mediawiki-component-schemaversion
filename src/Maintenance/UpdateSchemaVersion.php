<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MWStake\MediaWiki\Component\SchemaVersion\SchemaVersionManager;
use Wikimedia\Rdbms\IMaintainableDatabase;

class UpdateSchemaVersion extends LoggedUpdateMaintenance {

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates() {
		$db = $this->getDB( DB_PRIMARY );
		if ( !( $db instanceof IMaintainableDatabase ) ) {
			$this->error( 'Primary DB connection is not maintainable' );
		}

		$manager = new SchemaVersionManager( $this->getServiceContainer()->getHookContainer() );
		$manager->storeCurrentSchemaVersion( $db );
		$this->output( "Stored version of database schema\n" );

		// Never mark as "complete"
		return false;
	}

	/**
	 * @return string
	 */
	protected function getUpdateKey() {
		return 'mws_schema_version_update';
	}
}
