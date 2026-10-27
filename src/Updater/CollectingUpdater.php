<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Updater;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Maintenance\Maintenance;
use ReflectionMethod;
use Wikimedia\Rdbms\IMaintainableDatabase;

class CollectingUpdater extends DatabaseUpdater {
	/** @var array<string, array{path:int, fullPath:int|null}> */
	private const FILE_PARAMETER_MAP = [
		'applyPatch' => [ 'path' => 0, 'fullPath' => 1 ],
		'addTable' => [ 'path' => 1, 'fullPath' => 2 ],
		'addField' => [ 'path' => 2, 'fullPath' => 3 ],
		'addIndex' => [ 'path' => 2, 'fullPath' => 3 ],
		'dropField' => [ 'path' => 2, 'fullPath' => 3 ],
		'dropIndex' => [ 'path' => 2, 'fullPath' => 3 ],
		'dropTable' => [ 'path' => 1, 'fullPath' => 2 ],
		'renameIndex' => [ 'path' => 4, 'fullPath' => 5 ],
		'modifyField' => [ 'path' => 2, 'fullPath' => 3 ],
		'modifyPrimaryKey' => [ 'path' => 2, 'fullPath' => 3 ],
		'modifyTable' => [ 'path' => 1, 'fullPath' => 2 ],
	];

	/** @var array[] */
	private array $coreUpdates = [];

	/**
	 * @param IMaintainableDatabase &$db
	 * @param bool $shared
	 * @param Maintenance|null $maintenance
	 */
	public function __construct(
		IMaintainableDatabase &$db,
		bool $shared = false,
		?Maintenance $maintenance = null
	) {
		parent::__construct( $db, $shared, $maintenance );
	}

	/**
	 * Load all schema updates from registered extensions/skins into this updater.
	 * This only collects update definitions and does not execute them.
	 *
	 * @param HookContainer $hookContainer
	 * @return void
	 */
	public function collectFromHookContainer( HookContainer $hookContainer ): void {
		( new HookRunner( $hookContainer ) )->onLoadExtensionSchemaUpdates( $this );
	}

	/**
	 * Load MediaWiki core schema updates into this collector.
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function collectCoreUpdates(): void {
		$coreUpdater = DatabaseUpdater::newForDB( $this->db, $this->shared, $this->maintenance );

		$reflection = new ReflectionMethod( $coreUpdater, 'getCoreUpdateList' );
		$coreUpdates = $reflection->invoke( $coreUpdater );
		$this->coreUpdates = is_array( $coreUpdates ) ? $coreUpdates : [];
	}

	/**
	 * @return array[]
	 */
	public function getRawCoreUpdates(): array {
		return $this->coreUpdates;
	}

	/**
	 * @return array[]
	 */
	public function getRawUpdates(): array {
		return $this->extensionUpdates;
	}

	/**
	 * @return array[]
	 */
	public function getRawUpdatesOnVirtualDomains(): array {
		return $this->extensionUpdatesWithVirtualDomains;
	}

	/**
	 * Returns extension update definitions in normalized form, suitable for hashing.
	 *
	 * @return array[]
	 */
	public function getNormalizedUpdates(): array {
		$normalized = [];

		foreach ( $this->extensionUpdates as $update ) {
			$normalized[] = $this->normalizeUpdate( $update, null, 'extension' );
		}

		foreach ( $this->extensionUpdatesWithVirtualDomains as $update ) {
			$virtualDomain = array_shift( $update );
			$normalized[] = $this->normalizeUpdate( $update, (string)$virtualDomain, 'extension' );
		}

		return $this->sortNormalizedUpdates( $normalized );
	}

	/**
	 * @return array[]
	 */
	public function getNormalizedCoreUpdates(): array {
		$normalized = [];
		foreach ( $this->coreUpdates as $update ) {
			$normalized[] = $this->normalizeUpdate( $update, null, 'core' );
		}

		return $this->sortNormalizedUpdates( $normalized );
	}

	/**
	 * @return array[]
	 */
	public function getNormalizedAllUpdates(): array {
		return $this->sortNormalizedUpdates(
			array_merge(
				$this->getNormalizedCoreUpdates(),
				$this->getNormalizedUpdates()
			)
		);
	}

	/**
	 * @param array[] $normalized
	 * @return array[]
	 */
	private function sortNormalizedUpdates( array $normalized ): array {
		usort( $normalized, static function ( array $left, array $right ): int {
			$leftJson = json_encode( $left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '';
			$rightJson = json_encode( $right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '';

			return strcmp(
				$leftJson,
				$rightJson
			);
		} );

		return $normalized;
	}

	/**
	 * @param array $update
	 * @param string|null $virtualDomain
	 * @param string $source
	 * @return array
	 */
	private function normalizeUpdate(
		array $update,
		?string $virtualDomain = null,
		string $source = 'extension'
	): array {
		$callback = $update[0] ?? null;
		$params = array_slice( $update, 1 );

		return [
			'source' => $source,
			'virtualDomain' => $virtualDomain,
			'callback' => $this->describeCallback( $callback ),
			'targetFile' => $this->extractTargetFile( $callback, $params ),
			'params' => $this->normalizeValue( $params ),
		];
	}

	/**
	 * @param mixed $callback
	 * @param array $params
	 * @return array<string, string>|null
	 */
	private function extractTargetFile( $callback, array $params ): ?array {
		$methodName = $this->extractCallbackMethodName( $callback );
		if ( $methodName === null || !isset( self::FILE_PARAMETER_MAP[$methodName] ) ) {
			return null;
		}

		$config = self::FILE_PARAMETER_MAP[$methodName];
		$path = $params[$config['path']] ?? null;
		if ( !is_string( $path ) || $path === '' ) {
			return null;
		}

		$isFullPath = false;
		if ( $config['fullPath'] !== null ) {
			$isFullPath = !empty( $params[$config['fullPath']] );
		}

		$resolvedPath = $this->resolveTargetFilePath( $path, $isFullPath );
		if ( $resolvedPath === null ) {
			return null;
		}

		$fileHash = hash_file( 'sha256', $resolvedPath );
		if ( $fileHash === false ) {
			return null;
		}

		return [
			'path' => $this->canonicalizePath( $resolvedPath ),
			'hash' => $fileHash,
		];
	}

	/**
	 * @param mixed $callback
	 * @return string|null
	 */
	private function extractCallbackMethodName( $callback ): ?string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && count( $callback ) === 2 && is_string( $callback[1] ) ) {
			return $callback[1];
		}

		return null;
	}

	/**
	 * @param string $path
	 * @param bool $isFullPath
	 * @return string|null
	 */
	private function resolveTargetFilePath( string $path, bool $isFullPath ): ?string {
		$candidate = realpath( $path );
		if ( $candidate !== false && is_file( $candidate ) ) {
			return $candidate;
		}

		if ( $isFullPath ) {
			return null;
		}

		$candidate = realpath( $this->patchPath( $this->db, $path ) );
		if ( $candidate !== false && is_file( $candidate ) ) {
			return $candidate;
		}

		return null;
	}

	/**
	 * @param string $resolvedPath
	 * @return string
	 */
	private function canonicalizePath( string $resolvedPath ): string {
		if ( defined( 'MW_INSTALL_PATH' ) ) {
			$mwInstallPath = rtrim( MW_INSTALL_PATH, '/' ) . '/';
			if ( str_starts_with( $resolvedPath, $mwInstallPath ) ) {
				return substr( $resolvedPath, strlen( $mwInstallPath ) );
			}
		}

		return $resolvedPath;
	}

	/**
	 * @param mixed $callback
	 * @return string
	 */
	private function describeCallback( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && count( $callback ) === 2 ) {
			[ $classOrObject, $method ] = $callback;
			$class = is_object( $classOrObject ) ? get_class( $classOrObject ) : (string)$classOrObject;
			return $class . '::' . (string)$method;
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback );
		}

		return (string)$callback;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalizeValue( $value ) {
		if ( is_array( $value ) ) {
			$isList = $value === [] || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $isList ) {
				return array_map( function ( $item ) {
					return $this->normalizeValue( $item );
				}, $value );
			}

			$normalized = [];
			$keys = array_keys( $value );
			sort( $keys );
			foreach ( $keys as $key ) {
				$normalized[(string)$key] = $this->normalizeValue( $value[$key] );
			}
			return $normalized;
		}

		if ( is_object( $value ) ) {
			if ( method_exists( $value, '__toString' ) ) {
				return (string)$value;
			}
			return get_class( $value );
		}

		return $value;
	}

	/**
	 * @inheritDoc
	 */
	protected function getCoreUpdateList() {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	protected function getInitialUpdateKeys() {
		return [];
	}
}
