<?php
/**
 * The config validator (`wp shelterkit-donations validate`) finds nothing.
 *
 * Its checks cover the contracts between the manifests, the abilities, the
 * products' input mappings, the emails and their templates, and the hooks.
 * Only the command ran them, and CI never ran the command. So when #58 gave
 * the memorial a computed `notify_family`, which hid the field's declared
 * shape from the email check, nothing noticed (#57). This runs the same
 * checks in CI.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Cli\Validate_Command;
use WP_UnitTestCase;

final class ConfigContractsTest extends WP_UnitTestCase {

	public function test_the_config_validator_finds_nothing(): void {
		$findings = ( new Validate_Command() )->findings();

		$this->assertSame(
			[],
			array_map( static fn( array $f ): string => "{$f['file']}:{$f['line']} {$f['message']}", $findings ),
			'Run `wp shelterkit-donations validate` for the same list.'
		);
	}
}
