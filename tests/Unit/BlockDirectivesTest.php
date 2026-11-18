<?php
/**
 * Every block's directives name things the JavaScript it loads defines.
 *
 * A directive naming a callback, action or piece of state that nothing
 * defines fails silently: the runtime resolves it to undefined and moves on.
 * The Donor Dashboard asked for `callbacks.init`, `callbacks.donorLevelLabel`
 * and `callbacks.lifetimeGivingFormatted`, none of which existed, and stayed
 * on "Loading your dashboard…" for good (#42). FormDirectivesTest checked the
 * three forms (#29); this checks every block, by reading each block's
 * render.php and following the imports from its view.js.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BlockDirectivesTest extends TestCase {

	/** Directive attributes whose value is JSON or a key, not a path. */
	private const NOT_PATHS = [ 'interactive', 'context', 'key', 'each-key' ];

	/**
	 * @dataProvider blocks
	 */
	public function test_every_directive_is_a_plain_path( string $block ): void {
		foreach ( $this->directives( $block ) as [ $attribute, $value, $line ] ) {
			$this->assertMatchesRegularExpression(
				'/^!?[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/',
				$value,
				"blocks/{$block}/render.php:{$line} data-wp-{$attribute}=\"{$value}\" is not a plain path, so the runtime resolves it to undefined."
			);
		}
	}

	/**
	 * @dataProvider blocks
	 */
	public function test_every_directive_names_something_defined( string $block ): void {
		$render = (string) file_get_contents( $this->root() . "blocks/{$block}/render.php" );
		$script = $this->loaded_source( $this->root() . "blocks/{$block}/view.js" );

		$missing = [];
		foreach ( $this->directives( $block ) as [ $attribute, $value, $line ] ) {
			if ( ! preg_match( '/^!?(state|actions|callbacks)\.([A-Za-z_$][\w$]*)/', $value, $m ) ) {
				continue; // context.*, or a path the first test already rejects.
			}
			$name = preg_quote( $m[2], '/' );
			// A member definition, not a call: `name(`, `*name(`, `get name(`,
			// `name:`, or shorthand `name,` — never preceded by a dot.
			$in_js = (bool) preg_match(
				"/(?<![\\w.\$])(?:get\\s+|async\\s+|\\*\\s*)?{$name}\\s*(?:\\(|:|,|\\})/",
				$this->section( $script, $m[1] )
			);
			// State may come from the server alone (wp_interactivity_state).
			$in_state = 'state' === $m[1] && (bool) preg_match( "/['\"]{$name}['\"]\\s*=>/", $render );

			if ( ! $in_js && ! $in_state ) {
				$missing[] = "blocks/{$block}/render.php:{$line} data-wp-{$attribute}=\"{$value}\"";
			}
		}

		$this->assertSame( [], $missing, 'Nothing these directives name is defined in the scripts the block loads (or, for state, in its server state).' );
	}

	/**
	 * Blocks whose render.php carries directives.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blocks(): array {
		$out = [];
		foreach ( glob( $this->root() . 'blocks/*/render.php' ) as $render ) {
			if ( str_contains( (string) file_get_contents( $render ), 'data-wp-' ) ) {
				$block         = basename( dirname( $render ) );
				$out[ $block ] = [ $block ];
			}
		}
		return $out;
	}

	public function test_the_scan_finds_the_blocks(): void {
		// Guard the guard: a broken glob or pattern would pass vacuously.
		$this->assertGreaterThanOrEqual( 8, count( $this->blocks() ) );
		$this->assertGreaterThan( 50, count( $this->directives( 'memorial-wall' ) ) );
	}

	/**
	 * Path-valued directives in a block's render.php, written either as HTML
	 * attributes or as get_block_wrapper_attributes() array entries.
	 *
	 * @param string $block Block directory.
	 * @return array<int, array{0: string, 1: string, 2: int}> [ attribute, value, line ].
	 */
	private function directives( string $block ): array {
		$source = (string) file_get_contents( $this->root() . "blocks/{$block}/render.php" );
		$found  = [];
		$scan   = [
			'/\bdata-wp-([a-z-]+?)(?:--[\w-]+)?="([^"]*)"/',
			'/[\'"]data-wp-([a-z-]+?)(?:--[\w-]+)?[\'"]\s*=>\s*\'([^\']*)\'/',
			'/\[\s*[\'"]data-wp-([a-z-]+?)(?:--[\w-]+)?[\'"]\s*\]\s*=\s*\'([^\']*)\'/',
		];
		foreach ( $scan as $pattern ) {
			preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
			foreach ( $matches as $match ) {
				$attribute = $match[1][0];
				$value     = $match[2][0];
				// Values PHP fills in are not literal paths. (JSON values are
				// all in NOT_PATHS; a bracket in any other value is the bug.)
				if ( in_array( $attribute, self::NOT_PATHS, true ) || '' === $value || preg_match( '/<\?|\$/', $value ) ) {
					continue;
				}
				$found[] = [ $attribute, $value, substr_count( substr( $source, 0, $match[0][1] ), "\n" ) + 1 ];
			}
		}
		return $found;
	}

	/**
	 * The bodies of every `state: {`, `actions: {` or `callbacks: {` object in
	 * a script, so `callbacks.init` is not satisfied by an `actions.init`.
	 * An object that spreads another (`...base`) may get the name from it,
	 * so then the whole script counts.
	 *
	 * @param string $script  Loaded source.
	 * @param string $section state, actions or callbacks.
	 * @return string
	 */
	private function section( string $script, string $section ): string {
		$bodies = '';
		preg_match_all( '/\b' . $section . '\s*:\s*\{/', $script, $matches, PREG_OFFSET_CAPTURE );
		foreach ( $matches[0] as [ $text, $offset ] ) {
			$start = $offset + strlen( $text );
			$depth = 1;
			$i     = $start;
			for ( $length = strlen( $script ); $i < $length && $depth > 0; $i++ ) {
				$depth += '{' === $script[ $i ] ? 1 : ( '}' === $script[ $i ] ? -1 : 0 );
			}
			$bodies .= substr( $script, $start, $i - $start - 1 ) . "\n";
		}
		return ( '' === $bodies || str_contains( $bodies, '...' ) ) ? $script : $bodies;
	}

	/**
	 * A script and everything it imports by relative path, concatenated.
	 *
	 * @param string $file  Absolute path.
	 * @param array  $seen  Files already read.
	 * @return string
	 */
	private function loaded_source( string $file, array &$seen = [] ): string {
		$real = realpath( $file );
		if ( false === $real || isset( $seen[ $real ] ) ) {
			return '';
		}
		$seen[ $real ] = true;
		$source        = (string) file_get_contents( $real );
		preg_match_all( '/\bimport\s+(?:[^\'";]*?\s+from\s+)?[\'"](\.{1,2}\/[^\'"]+)[\'"]/', $source, $imports );
		foreach ( $imports[1] as $relative ) {
			$source .= "\n" . $this->loaded_source( dirname( $real ) . '/' . $relative, $seen );
		}
		return $source;
	}

	private function root(): string {
		return dirname( __DIR__, 2 ) . '/';
	}
}
