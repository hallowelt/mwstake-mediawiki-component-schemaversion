<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_SCHEMAVERSION_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_SCHEMAVERSION_VERSION', '1.0.0' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
->register( 'schemaversion', static function () {

} );

