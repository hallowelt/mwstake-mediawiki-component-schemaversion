<?php

use MWStake\MediaWiki\Component\SchemaVersion\Maintenance\UpdateSchemaVersion;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_SCHEMAVERSION_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_SCHEMAVERSION_VERSION', '1.0.0' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
->register( 'schemaversion', static function () {
	$GLOBALS['wgExtensionFunctions'][] = static function () {
		$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( DatabaseUpdater $updater ) {
			$dbType = $updater->getDB()->getType();
			$updater->addExtensionTable( 'mws_schema_version', __DIR__ . "/db/$dbType/mws_schema_version.sql" );

			$updater->addPostDatabaseUpdateMaintenance( UpdateSchemaVersion::class );
		} );
	};
} );
