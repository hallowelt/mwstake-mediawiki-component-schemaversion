<?php

namespace MWStake\MediaWiki\Component\SchemaVersion\Tests\Updater;

use MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

class CollectingUpdaterTest extends TestCase {
	/** @var string[] */
	private array $tempFiles = [];

	protected function tearDown(): void {
		parent::tearDown();

		foreach ( $this->tempFiles as $tempFile ) {
			if ( is_file( $tempFile ) ) {
				unlink( $tempFile );
			}
		}
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater::getNormalizedUpdates
	 * @return void
	 */
	public function testGetNormalizedUpdatesNormalizesVirtualDomainsAndTargetFiles(): void {
		$updater = $this->newUpdaterWithoutConstructor();
		$directFile = $this->makeTempFile( 'direct-file-content' );
		$virtualDomainFile = $this->makeTempFile( 'virtual-domain-file-content' );

		$this->setPropertyValue( $updater, 'extensionUpdates', [
			[ 'customCallback', [ 'z' => 'last', 'a' => 'first' ] ],
			[ 'applyPatch', $directFile, true ],
		] );
		$this->setPropertyValue( $updater, 'extensionUpdatesWithVirtualDomains', [
			[ 'analytics', 'addTable', 'schema_table', $virtualDomainFile, true ],
		] );

		$normalized = $updater->getNormalizedUpdates();

		$this->assertCount( 3, $normalized );

		$custom = $this->findByCallback( $normalized, 'customCallback' );
		$this->assertNotNull( $custom );
		$this->assertSame( 'extension', $custom['source'] );
		$this->assertNull( $custom['virtualDomain'] );
		$this->assertNull( $custom['targetFile'] );
		$this->assertSame(
			[ [ 'a' => 'first', 'z' => 'last' ] ],
			$custom['params']
		);

		$applyPatch = $this->findByCallback( $normalized, 'applyPatch' );
		$this->assertNotNull( $applyPatch );
		$this->assertNull( $applyPatch['virtualDomain'] );
		$this->assertSame( $this->expectedTargetFile( $directFile ), $applyPatch['targetFile'] );
		$this->assertSame( [ $directFile, true ], $applyPatch['params'] );

		$virtualDomain = $this->findByCallback( $normalized, 'addTable' );
		$this->assertNotNull( $virtualDomain );
		$this->assertSame( 'analytics', $virtualDomain['virtualDomain'] );
		$this->assertSame( $this->expectedTargetFile( $virtualDomainFile ), $virtualDomain['targetFile'] );
		$this->assertSame( [ 'schema_table', $virtualDomainFile, true ], $virtualDomain['params'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater::getNormalizedUpdates
	 * @return void
	 */
	public function testGetNormalizedUpdatesOmitsTargetFileForMissingFullPath(): void {
		$updater = $this->newUpdaterWithoutConstructor();
		$missingPath = sys_get_temp_dir() . '/missing-' . uniqid( '', true ) . '.sql';

		$this->setPropertyValue( $updater, 'extensionUpdates', [
			[ 'applyPatch', $missingPath, true ],
		] );
		$this->setPropertyValue( $updater, 'extensionUpdatesWithVirtualDomains', [] );

		$normalized = $updater->getNormalizedUpdates();

		$this->assertCount( 1, $normalized );
		$this->assertNull( $normalized[0]['targetFile'] );
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater::getNormalizedCoreUpdates
	 * @covers \MWStake\MediaWiki\Component\SchemaVersion\Updater\CollectingUpdater::getNormalizedAllUpdates
	 * @return void
	 */
	public function testGetNormalizedCoreAndAllUpdatesUseExpectedSource(): void {
		$updater = $this->newUpdaterWithoutConstructor();
		$coreFile = $this->makeTempFile( 'core-file-content' );

		$this->setPropertyValue( $updater, 'coreUpdates', [
			[ 'applyPatch', $coreFile, true ],
			[ [ new stdClass(), 'run' ], 123 ],
		] );
		$this->setPropertyValue( $updater, 'extensionUpdates', [
			[ 'extensionUpdate', 'x' ],
		] );
		$this->setPropertyValue( $updater, 'extensionUpdatesWithVirtualDomains', [] );

		$core = $updater->getNormalizedCoreUpdates();
		$all = $updater->getNormalizedAllUpdates();

		$this->assertCount( 2, $core );
		$this->assertSame( [ 'core', 'core' ], array_column( $core, 'source' ) );

		$this->assertCount( 3, $all );
		$this->assertSame( [ 'core', 'core', 'extension' ], array_column( $all, 'source' ) );
	}

	private function newUpdaterWithoutConstructor(): CollectingUpdater {
		$reflection = new ReflectionClass( CollectingUpdater::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * @param array[] $updates
	 * @param string $callback
	 * @return array|null
	 */
	private function findByCallback( array $updates, string $callback ): ?array {
		foreach ( $updates as $update ) {
			if ( ( $update['callback'] ?? null ) === $callback ) {
				return $update;
			}
		}

		return null;
	}

	private function makeTempFile( string $content ): string {
		$tempFile = tempnam( sys_get_temp_dir(), 'collecting-updater-test-' );
		$this->assertIsString( $tempFile );
		file_put_contents( $tempFile, $content );
		$this->tempFiles[] = $tempFile;

		return $tempFile;
	}

	/**
	 * @return array{path:string, hash:string}
	 */
	private function expectedTargetFile( string $path ): array {
		$resolvedPath = realpath( $path );
		$this->assertNotFalse( $resolvedPath );
		return [
			'path' => $resolvedPath,
			'hash' => hash_file( 'sha256', $resolvedPath ),
		];
	}

	/**
	 * @param stdClass $object
	 * @param string $property
	 * @param mixed $value
	 * @return void
	 */
	private function setPropertyValue( object $object, string $property, $value ): void {
		$reflectionClass = new ReflectionClass( $object );
		while ( $reflectionClass !== false ) {
			if ( $reflectionClass->hasProperty( $property ) ) {
				$reflectionProperty = $reflectionClass->getProperty( $property );
				$reflectionProperty->setAccessible( true );
				$reflectionProperty->setValue( $object, $value );
				return;
			}
			$reflectionClass = $reflectionClass->getParentClass();
		}

		$this->fail( "Property '$property' not found." );
	}
}
