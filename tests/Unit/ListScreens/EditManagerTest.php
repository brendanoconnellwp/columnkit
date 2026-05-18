<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\ListScreens;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\ColumnRegistry;
use ColumnKit\ListScreens\EditManager;
use ColumnKit\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Regression: hierarchical list tables (Pages, hierarchical CPTs) populate
 * $wp_query->posts with `id=>parent` stdClass stubs / IDs, not WP_Post objects.
 * collect_core_data() must resolve those via get_post() so core Title/Date/Author
 * inline edit works there too (previously it skipped every non-WP_Post and returned []).
 */
final class EditManagerTest extends TestCase {
	private EditManager $em;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->em = new EditManager( new ColumnRegistry(), new SettingsRepository() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makePost( int $id, string $title ): \WP_Post {
		$p              = new \WP_Post();
		$p->ID          = $id;
		$p->post_title  = $title;
		$p->post_date   = '2026-05-17 09:30:00';
		$p->post_author = '7';
		return $p;
	}

	public function test_resolves_hierarchical_stub_rows_and_plain_ids(): void {
		$full = $this->makePost( 10, 'Real Post Object' );

		// stdClass id=>parent stub (Pages list) and a bare int id (also seen in the wild).
		$stub      = new \stdClass();
		$stub->ID  = 20;
		$bareId    = 30;

		$byId = [
			20 => $this->makePost( 20, 'About' ),
			30 => $this->makePost( 30, 'Sample Page' ),
		];
		Functions\when( 'get_post' )->alias(
			static fn( $id ) => $byId[ (int) $id ] ?? null
		);

		$GLOBALS['wp_query']        = new \stdClass();
		$GLOBALS['wp_query']->posts = [ $full, $stub, $bareId, 999 /* unresolvable */ ];

		$data = $this->em->collect_core_data();

		$this->assertCount( 3, $data );
		$this->assertSame( 'Real Post Object', $data[10]['title'] );
		$this->assertSame( 'About', $data[20]['title'] );        // stdClass stub resolved
		$this->assertSame( 'Sample Page', $data[30]['title'] );  // bare id resolved
		$this->assertArrayNotHasKey( 999, $data );               // get_post() returned null
		$this->assertSame( '2026-05-17', $data[20]['date'] );
		$this->assertSame( '7', $data[20]['author'] );
	}

	public function test_empty_query_returns_empty(): void {
		$GLOBALS['wp_query']        = new \stdClass();
		$GLOBALS['wp_query']->posts = [];
		$this->assertSame( [], $this->em->collect_core_data() );
	}
}
