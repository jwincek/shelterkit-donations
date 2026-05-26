<?php
/**
 * WP-CLI: starter-shelter validate
 *
 * Static-analysis-style consistency checker for the plugin's
 * config-driven contracts. Catches the classes of bug that recurred
 * across the audit pattern sweep — ability references that don't
 * resolve, product mappings that target nonexistent ability inputs,
 * domain action hooks fired with no listener (or vice versa), and
 * products.json `ability` ids that aren't declared.
 *
 * Each check is conservative (low false-positive rate) and operates
 * on static string matching across config JSON + PHP source.
 *
 * @package Starter_Shelter
 * @subpackage Cli
 * @since 1.1.2
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Cli;

use Starter_Shelter\Core\Field_Manifest;
use WP_CLI;

/**
 * `wp starter-shelter validate` — config/code contract validator.
 *
 * @since 1.1.2
 */
class Validate_Command {

	/**
	 * Validate the plugin's config + code contracts.
	 *
	 * ## OPTIONS
	 *
	 * [--check=<name>]
	 * : Run only one named check. One of: abilities, products, hooks, mappings.
	 *
	 * [--format=<format>]
	 * : Output format. One of: human (default), json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp starter-shelter validate
	 *     wp starter-shelter validate --check=abilities
	 *     wp starter-shelter validate --format=json
	 *
	 * @when before_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flag args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$only   = $assoc_args['check'] ?? null;
		$format = $assoc_args['format'] ?? 'human';

		$abilities_config = $this->load_json( 'config/abilities.json' );
		$products_config  = $this->load_json( 'config/products.json' );
		$emails_config    = $this->load_json( 'config/emails.json' );

		// Manifests own a growing subset of abilities and products; project
		// them and merge so checks see the full declared surface (both
		// sources).
		$this->init_manifest_loader();
		foreach ( Field_Manifest::get_abilities() as $ability_id => $cfg ) {
			$abilities_config['abilities'][ $ability_id ] = $cfg;
		}
		foreach ( Field_Manifest::get_products() as $sku_prefix => $cfg ) {
			$products_config['products'][ $sku_prefix ] = $cfg;
		}
		foreach ( Field_Manifest::get_emails() as $email_id => $cfg ) {
			$emails_config['emails'][ $email_id ] = $cfg;
		}

		$findings = [];

		if ( null === $only || 'abilities' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_ability_references( $abilities_config )
			);
		}
		if ( null === $only || 'products' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_product_ability_ids( $products_config, $abilities_config )
			);
		}
		if ( null === $only || 'mappings' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_product_input_mappings( $products_config, $abilities_config )
			);
		}
		if ( null === $only || 'hooks' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_domain_action_hooks( $emails_config )
			);
		}
		if ( null === $only || 'manifests' === $only ) {
			$findings = array_merge(
				$findings,
				$this->check_manifest_coverage(),
				$this->check_manifest_abilities(),
				$this->check_manifest_products(),
				$this->check_manifest_meta_boxes(),
				$this->check_manifest_checkout_fields(),
				$this->check_manifest_emails(),
				$this->check_producer_arg_counts(),
				$this->check_ability_return_shapes( $abilities_config ),
				$this->check_template_field_accesses()
			);
		}

		$this->emit( $findings, $format );
	}

	/**
	 * Bootstrap Field_Manifest for use during validation (CLI runs
	 * before WP load, so we initialize the loader explicitly).
	 *
	 * @since 1.1.2
	 */
	private function init_manifest_loader(): void {
		if ( ! class_exists( Field_Manifest::class ) ) {
			require STARTER_SHELTER_PATH . 'includes/core/class-field-manifest.php';
		}
		Field_Manifest::init( STARTER_SHELTER_PATH . 'config' );
	}

	/**
	 * Check 11: producer-side arg-count check.
	 *
	 * For every email that declares a trigger_hook + trigger_args, find
	 * the matching `do_action('hook', ...)` call sites in PHP source and
	 * flag producers that fire FEWER args than the email expects. The
	 * "expecting more than the producer fires" case is the runtime bug:
	 * the listener registers for N args, gets only M < N from the
	 * producer, and the missing args arrive as null. Catches contract
	 * drift like donor-annual-summary directly.
	 *
	 * Producers that fire MORE args than an email expects are not
	 * flagged — `add_action` registers the listener for only as many
	 * args as it declared, so extra producer args are silently dropped.
	 * That's a documentation gap (the email could declare a richer
	 * shape) but not a runtime bug, so it's out of scope for this check.
	 *
	 * Uses a token-based parser (not regex) so multi-line do_action
	 * calls, nested arrays, and function-call args are counted correctly.
	 *
	 * Calls without a literal-string first arg (`do_action($hook, ...)`)
	 * are skipped — the hook name isn't statically resolvable.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_producer_arg_counts(): array {
		// Map: trigger_hook → list of { email_id, count, source }.
		$expected = [];

		foreach ( Field_Manifest::list_all_manifests() as $name ) {
			$manifest = Field_Manifest::get( $name );
			if ( ! is_array( $manifest ) ) {
				continue;
			}
			$source_file = sprintf( 'config/manifests/%s.php', $name );
			foreach ( $manifest['emails'] ?? [] as $email_id => $cfg ) {
				$hook = $cfg['trigger_hook'] ?? null;
				$args = $cfg['trigger_args'] ?? [];
				if ( is_string( $hook ) && is_array( $args ) && ! empty( $args ) ) {
					$expected[ $hook ][] = [
						'email_id' => $email_id,
						'count'    => count( $args ),
						'source'   => $source_file,
					];
				}
			}
		}

		// Also include any unmigrated emails still in emails.json.
		$emails_raw  = @file_get_contents( STARTER_SHELTER_PATH . 'config/emails.json' );
		$emails_json = is_string( $emails_raw ) ? json_decode( $emails_raw, true ) : null;
		if ( is_array( $emails_json ) ) {
			foreach ( $emails_json['emails'] ?? [] as $email_id => $cfg ) {
				$hook = $cfg['trigger_hook'] ?? null;
				$args = $cfg['trigger_args'] ?? [];
				if ( is_string( $hook ) && is_array( $args ) && ! empty( $args ) ) {
					$expected[ $hook ][] = [
						'email_id' => $email_id,
						'count'    => count( $args ),
						'source'   => 'config/emails.json',
					];
				}
			}
		}

		if ( empty( $expected ) ) {
			return [];
		}

		$findings = [];

		foreach ( $this->iter_php_files() as $file ) {
			$contents = @file_get_contents( $file );
			if ( ! is_string( $contents ) ) {
				continue;
			}

			foreach ( $this->parse_do_action_calls( $contents ) as $call ) {
				if ( ! isset( $expected[ $call['hook'] ] ) ) {
					continue;
				}

				// arg_count from the parser counts ALL args including the
				// hook name itself; listener args = arg_count - 1.
				$producer_listener_args = $call['arg_count'] - 1;

				foreach ( $expected[ $call['hook'] ] as $exp ) {
					// Only the producer-too-few case is a runtime bug —
					// producer-too-many is silently dropped by the
					// listener's declared arg count. See method docblock.
					if ( $exp['count'] <= $producer_listener_args ) {
						continue;
					}
					$findings[] = [
						'file'    => $this->relative_path( $file ),
						'line'    => $call['line'],
						'message' => sprintf(
							'do_action("%s") fires only %d listener arg(s); email "%s" (%s) expects %d trigger_args — missing args arrive as null at runtime.',
							$call['hook'],
							$producer_listener_args,
							$exp['email_id'],
							$exp['source'],
							$exp['count']
						),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 13: email template field-reference checking.
	 *
	 * For each email with a template file, parse the template's PHP and
	 * validate every `$var['literal_key']['literal_key']...` access chain
	 * against the same entity / args paths the placeholder check uses.
	 *
	 * Recognized access roots:
	 *  - `$data['alias']['...']` — direct entity_data access; resolves
	 *    to the entity alias declared in the email's `entities` block.
	 *  - `$args['key']['...']` — direct trigger_args access; resolves
	 *    against typed trigger_args (when declared).
	 *  - Locally-extracted aliases: a leading-block assignment of the
	 *    form `$var = $data['alias']` or `$var = $args['key']` (with
	 *    optional `?? default`) is tracked so that subsequent
	 *    `$var['key']` accesses route to the right entity/args path.
	 *
	 * Skips:
	 *  - Dynamic keys (`$var[$dyn]`, `$var[foo()]`).
	 *  - Variables that aren't tracked (foreach iteration vars,
	 *    arbitrary locals).
	 *  - Accesses rooted in entities not yet migrated to a manifest.
	 *  - `args.*` paths when trigger_args is the legacy flat-list form.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_template_field_accesses(): array {
		$findings = [];
		$template_base = STARTER_SHELTER_PATH . 'templates/';
		if ( ! is_dir( $template_base ) ) {
			return [];
		}

		// Collect emails from manifests + emails.json.
		$emails = [];
		foreach ( Field_Manifest::list_all_manifests() as $name ) {
			$manifest = Field_Manifest::get( $name );
			if ( ! is_array( $manifest ) ) {
				continue;
			}
			foreach ( $manifest['emails'] ?? [] as $email_id => $cfg ) {
				$emails[ $email_id ] = $cfg;
			}
		}
		$emails_raw  = @file_get_contents( STARTER_SHELTER_PATH . 'config/emails.json' );
		$emails_json = is_string( $emails_raw ) ? json_decode( $emails_raw, true ) : null;
		if ( is_array( $emails_json ) ) {
			foreach ( $emails_json['emails'] ?? [] as $email_id => $cfg ) {
				$emails[ $email_id ] = $cfg;
			}
		}

		foreach ( $emails as $email_id => $cfg ) {
			$template_rel = $cfg['template'] ?? '';
			if ( ! is_string( $template_rel ) || '' === $template_rel ) {
				continue;
			}
			$template_path = $template_base . $template_rel;
			if ( ! is_file( $template_path ) ) {
				continue;
			}

			$contents = @file_get_contents( $template_path );
			if ( ! is_string( $contents ) ) {
				continue;
			}
			$tokens = @token_get_all( $contents );
			if ( ! is_array( $tokens ) ) {
				continue;
			}

			$analysis = $this->analyze_template_tokens( $tokens );
			$rel_file = 'templates/' . $template_rel;
			$entities_in_email = $cfg['entities'] ?? [];
			$trigger_args      = $cfg['trigger_args'] ?? [];

			foreach ( $analysis['accesses'] as $access ) {
				$var  = $access['var'];
				$keys = $access['keys'];
				$line = $access['line'];

				// Resolve to a dot-path the existing walker understands.
				if ( 'data' === $var ) {
					// $data['alias']['key'] → entity alias path.
					if ( empty( $keys ) ) {
						continue;
					}
					$path = implode( '.', $keys );
				} elseif ( 'args' === $var ) {
					$path = 'args.' . implode( '.', $keys );
				} elseif ( isset( $analysis['var_map'][ $var ] ) ) {
					$root     = $analysis['var_map'][ $var ]['root'];
					$root_kx  = $analysis['var_map'][ $var ]['keys'];
					$combined = array_merge( $root_kx, $keys );
					if ( 'data' === $root ) {
						$path = implode( '.', $combined );
					} elseif ( 'args' === $root ) {
						$path = 'args.' . implode( '.', $combined );
					} else {
						continue; // Unknown root.
					}
				} else {
					continue; // Untracked variable; skip.
				}

				$err = $this->validate_path( $path, $entities_in_email, $email_id, "template `\${$var}[...]`", $trigger_args );
				if ( null !== $err ) {
					$findings[] = [
						'file'    => $rel_file,
						'line'    => $line,
						'message' => $err,
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * Walk template tokens to extract:
	 *  - `var_map`: locally-extracted aliases (e.g.,
	 *    `$donor = $data['donor']`) keyed by variable name.
	 *  - `accesses`: every `$var['literal_string']...` chain encountered.
	 *
	 * @since 1.1.2
	 *
	 * @param array $tokens
	 * @return array{var_map: array<string, array{root: string, keys: string[]}>, accesses: array<int, array{var: string, keys: string[], line: int}>}
	 */
	private function analyze_template_tokens( array $tokens ): array {
		$var_map  = [];
		$accesses = [];
		$n        = count( $tokens );
		$skip     = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];

		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_VARIABLE !== $t[0] ) {
				continue;
			}

			$var_name = ltrim( $t[1], '$' );
			$line     = $t[2];

			// Look forward: is this an assignment? (T_VARIABLE = ...)
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip, true ) ) {
				$j++;
			}
			$is_assignment = ( $j < $n && is_string( $tokens[ $j ] ) && '=' === $tokens[ $j ]
				// Reject `==`, `===`, `=>` (=> is T_DOUBLE_ARROW, not '=', so safe).
				&& ! ( $j + 1 < $n && is_string( $tokens[ $j + 1 ] ) && '=' === $tokens[ $j + 1 ] ) );

			if ( $is_assignment ) {
				// Parse RHS: skip to first T_VARIABLE.
				$k = $j + 1;
				while ( $k < $n && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], $skip, true ) ) {
					$k++;
				}
				if ( $k < $n && is_array( $tokens[ $k ] ) && T_VARIABLE === $tokens[ $k ][0] ) {
					$rhs_var = ltrim( $tokens[ $k ][1], '$' );
					$rhs_keys = $this->parse_access_chain( $tokens, $k + 1, $n );
					if ( ! empty( $rhs_keys ) ) {
						$var_map[ $var_name ] = [ 'root' => $rhs_var, 'keys' => $rhs_keys ];
					}
					$i = $k; // Skip past RHS variable; outer for-loop continues from there.
				}
				continue;
			}

			// Not an assignment — check for access chain.
			$keys = $this->parse_access_chain( $tokens, $i + 1, $n );
			if ( ! empty( $keys ) ) {
				$accesses[] = [
					'var'  => $var_name,
					'keys' => $keys,
					'line' => $line,
				];
			}
		}

		return [ 'var_map' => $var_map, 'accesses' => $accesses ];
	}

	/**
	 * From the token immediately after a T_VARIABLE, parse a chain of
	 * `[literal_string]` accesses. Returns the list of literal keys.
	 * Stops at the first non-`[STR]` token (including dynamic-key
	 * accesses, method calls, etc.).
	 *
	 * @since 1.1.2
	 *
	 * @param array $tokens
	 * @param int   $start  Index of the token immediately after the variable.
	 * @param int   $n
	 * @return string[]
	 */
	private function parse_access_chain( array $tokens, int $start, int $n ): array {
		$keys = [];
		$skip = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
		$i    = $start;

		while ( $i < $n ) {
			// Skip whitespace/comments before `[`.
			while ( $i < $n && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $skip, true ) ) {
				$i++;
			}
			if ( $i >= $n || ! is_string( $tokens[ $i ] ) || '[' !== $tokens[ $i ] ) {
				break;
			}
			$i++;
			while ( $i < $n && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $skip, true ) ) {
				$i++;
			}
			if ( $i >= $n || ! is_array( $tokens[ $i ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ][0] ) {
				break; // Dynamic key, integer key, etc. — stop chain.
			}
			$key = trim( $tokens[ $i ][1], "'\"" );
			$i++;
			while ( $i < $n && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $skip, true ) ) {
				$i++;
			}
			if ( $i >= $n || ! is_string( $tokens[ $i ] ) || ']' !== $tokens[ $i ] ) {
				break;
			}
			$i++;
			$keys[] = $key;
		}

		return $keys;
	}

	/**
	 * Check 12: ability handler return shape vs declared output_schema.
	 *
	 * For each ability with an `output_schema.properties` block, find the
	 * handler function in `includes/abilities/*.php`, parse its
	 * `return [...]` statements, and surface drift between the
	 * literal-array keys it returns and the keys the schema declares:
	 *
	 *  - REQUIRED keys in schema absent from EVERY non-WP_Error return:
	 *    the ability isn't fulfilling its declared contract.
	 *  - Keys returned but not declared in schema properties: schema
	 *    is undocumented for those fields (drift, or intentional but
	 *    undocumented extras).
	 *
	 * Dynamic returns (`return $variable`, `return new WP_Error(...)`,
	 * etc.) and abilities without declared output properties are
	 * skipped. Aggregate covers all non-WP_Error returns in the
	 * handler — a key appearing in any return counts as "potentially
	 * returned."
	 *
	 * @param array<string, mixed> $abilities_config Parsed + merged abilities config.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_ability_return_shapes( array $abilities_config ): array {
		$findings = [];
		$abilities_dir = STARTER_SHELTER_PATH . 'includes/abilities/';
		if ( ! is_dir( $abilities_dir ) ) {
			return [];
		}

		// Cache parsed files to avoid re-tokenizing.
		$parsed = [];

		foreach ( $abilities_config['abilities'] ?? [] as $ability_id => $cfg ) {
			$properties = $cfg['output_schema']['properties'] ?? null;
			if ( ! is_array( $properties ) || empty( $properties ) ) {
				continue;
			}
			$required = $cfg['output_schema']['required'] ?? [];
			if ( ! is_array( $required ) ) {
				$required = [];
			}

			[ $file_basename, $fn_name ] = $this->resolve_ability_handler( $ability_id, $cfg );
			$file_path = $abilities_dir . $file_basename . '.php';
			if ( ! is_file( $file_path ) ) {
				continue; // Can't find handler file; skip silently.
			}

			if ( ! isset( $parsed[ $file_path ] ) ) {
				$contents = @file_get_contents( $file_path );
				if ( ! is_string( $contents ) ) {
					$parsed[ $file_path ] = [];
					continue;
				}
				$parsed[ $file_path ] = $this->parse_function_return_keys( $contents );
			}

			$handler = $parsed[ $file_path ][ $fn_name ] ?? null;
			if ( null === $handler ) {
				continue; // Function not found; could be ability-with-no-handler.
			}

			// Union of all return-array keys across all return statements.
			$returned_keys = [];
			foreach ( $handler['returns'] as $return ) {
				foreach ( $return['keys'] as $key ) {
					$returned_keys[ $key ] = true;
				}
			}
			$returned_keys = array_keys( $returned_keys );

			$schema_keys = array_keys( $properties );
			$rel_file    = 'includes/abilities/' . $file_basename . '.php';

			// Required-key-not-returned (always a bug).
			foreach ( $required as $req ) {
				if ( ! in_array( $req, $returned_keys, true ) ) {
					$findings[] = [
						'file'    => $rel_file,
						'line'    => $handler['line'],
						'message' => sprintf(
							'Ability "%s" declares required output key "%s" but %s() never returns an array with that key.',
							$ability_id,
							$req,
							$fn_name
						),
					];
				}
			}

			// Returned-key-not-in-schema (drift).
			foreach ( $returned_keys as $rk ) {
				if ( ! in_array( $rk, $schema_keys, true ) ) {
					$findings[] = [
						'file'    => $rel_file,
						'line'    => $handler['line'],
						'message' => sprintf(
							'Ability "%s" handler %s() returns key "%s" not declared in output_schema.properties.',
							$ability_id,
							$fn_name,
							$rk
						),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * Map an ability id to its handler file basename + function name.
	 * Honors an explicit `callback` field on the ability config (e.g.,
	 * `'callback' => 'Starter_Shelter\\Abilities\\Memorials\\list_memorials'`);
	 * otherwise derives via Provider's convention (e.g.,
	 * `shelter-donations/create` → file `donations`, function `create`).
	 *
	 * @return array{0: string, 1: string} [file_basename, function_name]
	 */
	private function resolve_ability_handler( string $ability_id, array $cfg ): array {
		if ( isset( $cfg['callback'] ) && is_string( $cfg['callback'] ) ) {
			// Format: Starter_Shelter\Abilities\Namespace\function_name
			$parts = explode( '\\', $cfg['callback'] );
			$fn    = array_pop( $parts );
			$ns    = $parts[ count( $parts ) - 1 ] ?? '';
			return [ strtolower( $ns ), $fn ];
		}

		[ $prefix, $action ] = array_pad( explode( '/', $ability_id, 2 ), 2, '' );
		$file_basename = str_replace( 'shelter-', '', $prefix );
		$fn_name       = str_replace( '-', '_', $action );
		return [ $file_basename, $fn_name ];
	}

	/**
	 * Token-based parser that walks a PHP file and returns, per top-level
	 * function, the literal-array keys of every `return [...]` statement
	 * it contains. Skips:
	 *
	 *  - Dynamic returns (`return $variable`, `return foo($x)`, etc.).
	 *  - `return new WP_Error(...)` and similar non-array returns.
	 *  - Anonymous functions (no T_STRING name after T_FUNCTION).
	 *
	 * @since 1.1.2
	 *
	 * @param string $contents PHP file contents.
	 * @return array<string, array{line: int, returns: array<int, array{line: int, keys: string[]}>}>
	 */
	private function parse_function_return_keys( string $contents ): array {
		$tokens = @token_get_all( $contents );
		if ( ! is_array( $tokens ) ) {
			return [];
		}

		$functions = [];
		$n         = count( $tokens );

		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_FUNCTION !== $t[0] ) {
				continue;
			}

			// Function name (skip whitespace).
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( $j >= $n || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) {
				continue; // Anonymous function or method (after & for return-by-ref skipped).
			}
			$fn_name = $tokens[ $j ][1];
			$fn_line = $tokens[ $j ][2];

			// Find body's `{`.
			$body_start = -1;
			for ( $k = $j + 1; $k < $n; $k++ ) {
				if ( is_string( $tokens[ $k ] ) && '{' === $tokens[ $k ] ) {
					$body_start = $k;
					break;
				}
				if ( is_string( $tokens[ $k ] ) && ';' === $tokens[ $k ] ) {
					break; // Abstract method or declaration without body.
				}
			}
			if ( -1 === $body_start ) {
				continue;
			}

			// Walk body, tracking depth; find T_RETURN statements.
			$depth   = 1;
			$returns = [];
			for ( $k = $body_start + 1; $k < $n; $k++ ) {
				$tk = $tokens[ $k ];

				if ( is_string( $tk ) ) {
					if ( '{' === $tk ) {
						$depth++;
					} elseif ( '}' === $tk ) {
						$depth--;
						if ( 0 === $depth ) {
							break;
						}
					}
					continue;
				}

				if ( T_RETURN !== $tk[0] ) {
					continue;
				}
				$return_line = $tk[2];

				// Skip whitespace after `return`.
				$r = $k + 1;
				while ( $r < $n && is_array( $tokens[ $r ] ) && T_WHITESPACE === $tokens[ $r ][0] ) {
					$r++;
				}
				if ( $r >= $n ) {
					continue;
				}

				$next = $tokens[ $r ];
				$open_idx = -1;
				if ( is_string( $next ) && '[' === $next ) {
					$open_idx = $r;
				} elseif ( is_array( $next ) && T_ARRAY === $next[0] ) {
					// `array(...)` — advance to `(`.
					$p = $r + 1;
					while ( $p < $n ) {
						if ( is_string( $tokens[ $p ] ) && '(' === $tokens[ $p ] ) {
							$open_idx = $p;
							break;
						}
						$p++;
					}
				}
				if ( -1 === $open_idx ) {
					continue; // Dynamic return / WP_Error / etc.
				}

				$keys = $this->extract_array_literal_keys( $tokens, $open_idx, $n );
				if ( ! empty( $keys ) ) {
					$returns[] = [ 'line' => $return_line, 'keys' => $keys ];
				}
			}

			$functions[ $fn_name ] = [ 'line' => $fn_line, 'returns' => $returns ];
			$i                     = $k;
		}

		return $functions;
	}

	/**
	 * Extract the top-level literal string keys from an array literal
	 * starting at $open_idx (a `[` or `(` token). Recurses through
	 * nested arrays/calls via depth tracking but only returns keys
	 * at depth 1 of the outer array.
	 *
	 * @since 1.1.2
	 *
	 * @param array $tokens   Token stream.
	 * @param int   $open_idx Index of the outer-array opening bracket.
	 * @param int   $n        Total token count.
	 * @return string[]
	 */
	private function extract_array_literal_keys( array $tokens, int $open_idx, int $n ): array {
		$keys           = [];
		$depth          = 1;
		$expecting_key  = true;

		// Tokens that don't affect key/value parsing — skip without
		// touching `$expecting_key`. Comments are critical: a `// note`
		// after a trailing comma was previously resetting expecting_key
		// to false and silently dropping the next entry's key.
		$skip_token_types = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];

		for ( $i = $open_idx + 1; $i < $n && $depth > 0; $i++ ) {
			$t = $tokens[ $i ];

			if ( is_string( $t ) ) {
				if ( '[' === $t || '(' === $t || '{' === $t ) {
					$depth++;
				} elseif ( ']' === $t || ')' === $t || '}' === $t ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $t && 1 === $depth ) {
					$expecting_key = true;
				}
				continue;
			}

			if ( in_array( $t[0], $skip_token_types, true ) ) {
				continue;
			}

			if ( 1 !== $depth || ! $expecting_key ) {
				$expecting_key = false;
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				// Confirm next non-skippable token is `=>`.
				$j = $i + 1;
				while ( $j < $n && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip_token_types, true ) ) {
					$j++;
				}
				if ( $j < $n && is_array( $tokens[ $j ] ) && T_DOUBLE_ARROW === $tokens[ $j ][0] ) {
					$keys[] = trim( $t[1], "'\"" );
				}
			}
			$expecting_key = false;
		}

		return $keys;
	}

	/**
	 * Token-based parser for `do_action('hook_name', ...)` calls.
	 *
	 * Returns one entry per call with the hook name, total arg count
	 * (including the hook name), and the line the call starts on.
	 * Only calls whose first arg is a literal string starting with
	 * `starter_shelter_` are returned; dynamic-hook-name calls are
	 * skipped.
	 *
	 * @since 1.1.2
	 *
	 * @param string $contents PHP file contents.
	 * @return array<int, array{hook: string, arg_count: int, line: int}>
	 */
	private function parse_do_action_calls( string $contents ): array {
		$tokens = @token_get_all( $contents );
		if ( ! is_array( $tokens ) ) {
			return [];
		}

		$calls = [];
		$n     = count( $tokens );

		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] || 'do_action' !== $t[1] ) {
				continue;
			}

			// Find the `(` opening the call, skipping whitespace.
			$j = $i + 1;
			while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( $j >= $n || ! is_string( $tokens[ $j ] ) || '(' !== $tokens[ $j ] ) {
				continue;
			}

			$line      = $t[2];
			$depth     = 1;
			$arg_count = 1; // We're inside the first arg slot at $j+1.
			$hook_name = null;
			$k         = $j;

			for ( $k = $j + 1; $k < $n; $k++ ) {
				$tk = $tokens[ $k ];

				if ( is_string( $tk ) ) {
					if ( '(' === $tk || '[' === $tk || '{' === $tk ) {
						$depth++;
					} elseif ( ')' === $tk ) {
						$depth--;
						if ( 0 === $depth ) {
							break;
						}
					} elseif ( ']' === $tk || '}' === $tk ) {
						$depth--;
					} elseif ( ',' === $tk && 1 === $depth ) {
						$arg_count++;
					}
				} else {
					// Capture the first literal string at depth 1 as the hook.
					if ( null === $hook_name && 1 === $depth && T_CONSTANT_ENCAPSED_STRING === $tk[0] ) {
						$hook_name = trim( $tk[1], "'\"" );
					}
				}
			}

			if ( is_string( $hook_name ) && str_starts_with( $hook_name, 'starter_shelter_' ) ) {
				$calls[] = [
					'hook'      => $hook_name,
					'arg_count' => $arg_count,
					'line'      => $line,
				];
			}

			$i = $k;
		}

		return $calls;
	}

	/**
	 * Walk an `args.*` placeholder path against an email's typed
	 * `trigger_args` declaration. When trigger_args uses the legacy
	 * flat-list form (numeric keys), shapes aren't statically knowable
	 * and the walker silently skips. Recurses through `properties` on
	 * object-typed args; resolves `$ability_input` refs to the named
	 * ability's input_schema properties.
	 *
	 * @since 1.1.2
	 *
	 * @param string[] $parts        Path components, starting with 'args'.
	 * @param array    $trigger_args Email's trigger_args declaration.
	 * @param string   $email_id     Email id for diagnostics.
	 * @param string   $context      Where the path came from (for diagnostics).
	 * @param string   $path         Full original path (for diagnostics).
	 * @return string|null  Error message string on failure, null on success/skip.
	 */
	private function validate_args_path( array $parts, array $trigger_args, string $email_id, string $context, string $path ): ?string {
		// Legacy list-form trigger_args (numeric keys) → no static shape.
		if ( empty( $trigger_args ) || ! is_string( array_key_first( $trigger_args ) ) ) {
			return null;
		}

		$node = $trigger_args;

		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$key = $parts[ $i ];

			if ( ! isset( $node[ $key ] ) ) {
				return sprintf(
					'Email "%s" %s "%s" references "%s" which is not declared in trigger_args.',
					$email_id,
					$context,
					$path,
					implode( '.', array_slice( $parts, 0, $i + 1 ) )
				);
			}

			$next = $node[ $key ];
			if ( ! is_array( $next ) ) {
				return null;
			}

			// More components to walk? Descend into sub-properties.
			if ( $i + 1 < count( $parts ) ) {
				$sub = $this->resolve_args_sub_properties( $next );
				if ( null === $sub ) {
					return sprintf(
						'Email "%s" %s "%s" descends past "%s" but %s has no declared `properties` (or `$ability_input` ref) sub-tree.',
						$email_id,
						$context,
						$path,
						implode( '.', array_slice( $parts, 0, $i + 1 ) ),
						implode( '.', array_slice( $parts, 0, $i + 1 ) )
					);
				}
				$node = $sub;
			}
		}

		return null;
	}

	/**
	 * Resolve a trigger_args entry's sub-property tree. Object-typed
	 * entries use `properties`; entries can also $ability_input-ref
	 * an ability's input_schema properties for DRY documentation.
	 *
	 * @since 1.1.2
	 *
	 * @param array $node A trigger_args entry value.
	 * @return array<string, mixed>|null Sub-property map, or null if none.
	 */
	private function resolve_args_sub_properties( array $node ): ?array {
		if ( isset( $node['$ability_input'] ) && is_string( $node['$ability_input'] ) ) {
			$ability_id = $node['$ability_input'];
			foreach ( Field_Manifest::list_all_manifests() as $name ) {
				$manifest = Field_Manifest::get( $name );
				if ( null === $manifest ) {
					continue;
				}
				$ability = $manifest['abilities'][ $ability_id ] ?? null;
				if ( is_array( $ability ) && isset( $ability['input']['properties'] ) ) {
					return $ability['input']['properties'];
				}
			}
			return null;
		}

		return isset( $node['properties'] ) && is_array( $node['properties'] )
			? $node['properties']
			: null;
	}

	/**
	 * Check 10: per-manifest sanity for the emails they declare:
	 *
	 *  - email id must not also appear in emails.json (single source);
	 *  - each placeholder dot-path rooted in a declared entity alias
	 *    must resolve to an existing field or computed entry;
	 *  - the walker recurses through `properties` sub-trees on
	 *    object-typed entity fields, so paths like
	 *    `memorial.notify_family.enabled` validate against the
	 *    notify_family.properties.enabled declaration.
	 *
	 * `args.*` paths are skipped — the args' shape isn't statically
	 * knowable from the email config. Paths whose root entity isn't
	 * migrated to a manifest yet are also skipped.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_emails(): array {
		$emails_raw  = file_get_contents( STARTER_SHELTER_PATH . 'config/emails.json' );
		$emails_json = is_string( $emails_raw ) ? json_decode( $emails_raw, true ) : null;
		$json_emails = is_array( $emails_json ) ? ( $emails_json['emails'] ?? [] ) : [];

		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			$emails = $manifest['emails'] ?? null;
			if ( ! is_array( $emails ) ) {
				continue;
			}

			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( $emails as $email_id => $email_cfg ) {
				// Duplicate-source check.
				if ( isset( $json_emails[ $email_id ] ) ) {
					$findings[] = [
						'file'    => 'config/emails.json',
						'line'    => 0,
						'message' => sprintf(
							'Email "%s" is declared in both %s and config/emails.json. The manifest is the source of truth; remove from emails.json.',
							$email_id,
							$manifest_file
						),
					];
				}

				// Placeholder-path resolution. Each placeholder value is a
				// dot-path like `donor.full_name` or
				// `memorial.notify_family.enabled`. Validate the entity-
				// rooted prefix; skip `args.*`.
				$entities_in_email = $email_cfg['entities'] ?? [];

				$trigger_args = $email_cfg['trigger_args'] ?? [];

				foreach ( ( $email_cfg['placeholders'] ?? [] ) as $name => $path ) {
					$err = $this->validate_path( $path, $entities_in_email, $email_id, "placeholder $name", $trigger_args );
					if ( null !== $err ) {
						$findings[] = [
							'file'    => $manifest_file,
							'line'    => 0,
							'message' => $err,
						];
					}
				}

				// Also walk `condition` and `recipient_field` if present —
				// same path format.
				foreach ( [ 'condition', 'recipient_field' ] as $key ) {
					if ( ! isset( $email_cfg[ $key ] ) ) {
						continue;
					}
					$err = $this->validate_path(
						$email_cfg[ $key ],
						$entities_in_email,
						$email_id,
						$key,
						$trigger_args
					);
					if ( null !== $err ) {
						$findings[] = [
							'file'    => $manifest_file,
							'line'    => 0,
							'message' => $err,
						];
					}
				}
			}
		}

		return $findings;
	}

	/**
	 * Validate a single placeholder dot-path against the email's
	 * declared entities. Returns an error message string on failure,
	 * or null on success / skip.
	 *
	 * @since 1.1.2
	 *
	 * @param string $path              Dot-path (e.g., `donor.full_name`).
	 * @param array  $entities_in_email Email's `entities` block.
	 * @param string $email_id          Email id for diagnostics.
	 * @param string $context           Where the path came from (for diagnostics).
	 * @return string|null
	 */
	private function validate_path( string $path, array $entities_in_email, string $email_id, string $context, array $trigger_args = [] ): ?string {
		$parts = explode( '.', $path );
		$root  = $parts[0] ?? '';

		// args.* — walk against trigger_args when it carries types
		// (typed associative form). Legacy flat-list trigger_args has
		// no statically-knowable shape; skip in that case.
		if ( 'args' === $root ) {
			return $this->validate_args_path( $parts, $trigger_args, $email_id, $context, $path );
		}

		if ( ! isset( $entities_in_email[ $root ] ) ) {
			return sprintf(
				'Email "%s" %s "%s" references "%s" which is not in the email\'s `entities` block (or `args`).',
				$email_id,
				$context,
				$path,
				$root
			);
		}

		$entity_type = $entities_in_email[ $root ]['entity'] ?? null;
		if ( ! is_string( $entity_type ) ) {
			return null;
		}

		$entity_manifest = Field_Manifest::get( $entity_type );
		if ( null === $entity_manifest ) {
			// Entity not migrated yet — can't validate. Skip silently.
			return null;
		}

		// Walk the rest of the path against fields → properties → properties...
		// Implicit `id` and declared `relations` are always populated by
		// Entity_Hydrator at hydration time, so they're valid first-level
		// access keys even though they aren't in `fields`/`computed`.
		$node = array_merge(
			$entity_manifest['fields']    ?? [],
			$entity_manifest['computed']  ?? [],
			$entity_manifest['relations'] ?? [],
			[ 'id' => [ 'type' => 'integer', 'description' => 'WordPress post ID (always present)' ] ]
		);

		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$key = $parts[ $i ];
			if ( ! isset( $node[ $key ] ) ) {
				return sprintf(
					'Email "%s" %s "%s" references "%s" which does not exist in %s.',
					$email_id,
					$context,
					$path,
					implode( '.', array_slice( $parts, 0, $i + 1 ) ),
					$entity_type
				);
			}
			$next = $node[ $key ];
			if ( ! is_array( $next ) ) {
				return null;
			}
			// If there are more path components, descend into `properties`.
			if ( $i + 1 < count( $parts ) ) {
				if ( ! isset( $next['properties'] ) || ! is_array( $next['properties'] ) ) {
					return sprintf(
						'Email "%s" %s "%s" descends past "%s" but %s has no declared `properties` sub-tree.',
						$email_id,
						$context,
						$path,
						implode( '.', array_slice( $parts, 0, $i + 1 ) ),
						implode( '.', array_slice( $parts, 0, $i + 1 ) )
					);
				}
				$node = $next['properties'];
			}
		}

		return null;
	}

	/**
	 * Check 9: per-manifest sanity for the checkout_fields they declare.
	 *
	 * Self-contained entries (no matching entity field) are allowed —
	 * the overlay can fully describe the field. But any entry's
	 * projected output must resolve at least an `input_type` (so the
	 * checkout renderer knows what widget to render). A typo that
	 * matches neither a fields entry nor supplies its own input_type
	 * is flagged.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_checkout_fields(): array {
		$findings = [];

		// Iterates all manifests (entities + shared) since the _shared
		// manifest contributes checkout entries too.
		foreach ( Field_Manifest::list_all_manifests() as $name ) {
			$manifest = Field_Manifest::get( $name );
			if ( null === $manifest ) {
				continue;
			}

			$checkout_fields = $manifest['checkout_fields'] ?? null;
			if ( ! is_array( $checkout_fields ) ) {
				continue;
			}

			$fields        = $manifest['fields'] ?? [];
			$manifest_file = sprintf( 'config/manifests/%s.php', $name );

			foreach ( $checkout_fields as $field_name => $overlay ) {
				$form          = $fields[ $field_name ]['form'] ?? [];
				$has_input     = isset( $form['input_type'] )
					|| ( is_array( $overlay ) && isset( $overlay['input_type'] ) );
				if ( ! $has_input ) {
					$findings[] = [
						'file'    => $manifest_file,
						'line'    => 0,
						'message' => sprintf(
							'checkout_fields "%s" can\'t resolve an input_type — not a field in %s.fields and overlay doesn\'t supply one.',
							$field_name,
							$name
						),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 8: per-manifest sanity for the meta_boxes they declare.
	 *
	 * Each entry in a box's `fields` list resolves either to a manifest
	 * `fields` entry (bare string or `name => overrides` map) or is
	 * self-contained — the overlay supplies its own `input_type` (e.g.,
	 * display-only fields like `donor_level` that aren't editable
	 * entity fields). Same relaxation as check_manifest_checkout_fields.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_meta_boxes(): array {
		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}

			$meta_boxes = $manifest['meta_boxes'] ?? null;
			if ( ! is_array( $meta_boxes ) ) {
				continue;
			}

			$fields        = $manifest['fields'] ?? [];
			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( $meta_boxes as $box_id => $box_cfg ) {
				$box_fields = $box_cfg['fields'] ?? [];
				foreach ( $box_fields as $key => $value ) {
					$field_name = is_int( $key ) ? $value : $key;
					if ( ! is_string( $field_name ) ) {
						continue;
					}
					$overlay   = is_int( $key ) || ! is_array( $value ) ? [] : $value;
					$has_field = isset( $fields[ $field_name ] );
					$overlay_supplies_input = isset( $overlay['input_type'] );
					if ( ! $has_field && ! $overlay_supplies_input ) {
						$findings[] = [
							'file'    => $manifest_file,
							'line'    => 0,
							'message' => sprintf(
								'Meta box "%s" entry "%s" can\'t resolve an input_type — not a field in %s.fields and overlay doesn\'t supply one.',
								$box_id,
								$field_name,
								$entity
							),
						];
					}
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 7: per-manifest sanity for the products they declare. The
	 * SKU prefix must not also appear in products.json (single source
	 * of truth).
	 *
	 * Other product-level invariants (input_mapping target exists in
	 * the referenced ability; ability id is declared) are already
	 * covered by check_product_input_mappings + check_product_ability_ids
	 * — both of which see manifest products via the merge step in
	 * __invoke.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_products(): array {
		$products_raw  = file_get_contents( STARTER_SHELTER_PATH . 'config/products.json' );
		$products_json = is_string( $products_raw ) ? json_decode( $products_raw, true ) : null;
		$json_products = is_array( $products_json ) ? ( $products_json['products'] ?? [] ) : [];

		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}
			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( ( $manifest['products'] ?? [] ) as $sku_prefix => $cfg ) {
				if ( isset( $json_products[ $sku_prefix ] ) ) {
					$findings[] = [
						'file'    => 'config/products.json',
						'line'    => 0,
						'message' => sprintf(
							'Product "%s" is declared in both %s and config/products.json. The manifest is the source of truth; remove from products.json.',
							$sku_prefix,
							$manifest_file
						),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 6: per-manifest sanity for the abilities they declare:
	 *
	 *  - `$entity` refs in input/output schemas must point to fields
	 *    declared in the same entity manifest.
	 *  - The ability id must not also appear in abilities.json
	 *    (single source of truth).
	 *  - Names in `input.required` must appear in `input.properties`.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_abilities(): array {
		$abilities_raw = file_get_contents( STARTER_SHELTER_PATH . 'config/abilities.json' );
		$abilities_json = is_string( $abilities_raw ) ? json_decode( $abilities_raw, true ) : null;
		$json_abilities = is_array( $abilities_json ) ? ( $abilities_json['abilities'] ?? [] ) : [];

		$findings = [];

		foreach ( Field_Manifest::list_entities() as $entity ) {
			$manifest = Field_Manifest::get( $entity );
			if ( null === $manifest ) {
				continue;
			}
			$fields    = $manifest['fields'] ?? [];
			$abilities = $manifest['abilities'] ?? [];

			$manifest_file = sprintf( 'config/manifests/%s.php', $entity );

			foreach ( $abilities as $ability_id => $cfg ) {
				// Duplicate-source check.
				if ( isset( $json_abilities[ $ability_id ] ) ) {
					$findings[] = [
						'file'    => 'config/abilities.json',
						'line'    => 0,
						'message' => sprintf(
							'Ability "%s" is declared in both %s and config/abilities.json. The manifest is the source of truth; remove from abilities.json.',
							$ability_id,
							$manifest_file
						),
					];
				}

				// $entity ref + required-coverage checks on input + output.
				foreach ( [ 'input', 'output' ] as $schema_key ) {
					$schema = $cfg[ $schema_key ] ?? null;
					if ( ! is_array( $schema ) ) {
						continue;
					}

					$properties = $schema['properties'] ?? [];

					foreach ( $properties as $prop_name => $prop ) {
						if ( ! is_array( $prop ) || ! isset( $prop['$entity'] ) ) {
							continue;
						}
						$ref = $prop['$entity'];
						if ( ! isset( $fields[ $ref ] ) ) {
							$findings[] = [
								'file'    => $manifest_file,
								'line'    => 0,
								'message' => sprintf(
									'Ability "%s" %s.properties.%s has $entity ref "%s" that does not exist in %s.fields.',
									$ability_id,
									$schema_key,
									$prop_name,
									$ref,
									$entity
								),
							];
						}
					}

					if ( 'input' === $schema_key ) {
						foreach ( ( $schema['required'] ?? [] ) as $req ) {
							if ( ! isset( $properties[ $req ] ) ) {
								$findings[] = [
									'file'    => $manifest_file,
									'line'    => 0,
									'message' => sprintf(
										'Ability "%s" input.required lists "%s" but no such property in input.properties.',
										$ability_id,
										$req
									),
								];
							}
						}
					}
				}
			}
		}

		return $findings;
	}

	/**
	 * Check 5: every entity that has a manifest under config/manifests/
	 * has been removed from config/entities.json. Manifest is the source
	 * of truth; an entry left in entities.json is a second definition
	 * that will drift.
	 *
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_manifest_coverage(): array {
		$manifests_dir = STARTER_SHELTER_PATH . 'config/manifests/';
		if ( ! is_dir( $manifests_dir ) ) {
			return [];
		}

		$entities_raw = file_get_contents( STARTER_SHELTER_PATH . 'config/entities.json' );
		if ( false === $entities_raw ) {
			return [];
		}
		$entities_data = json_decode( $entities_raw, true );
		if ( ! is_array( $entities_data ) ) {
			return [];
		}

		$findings = [];

		foreach ( glob( $manifests_dir . '*.php' ) ?: [] as $file ) {
			$entity = basename( $file, '.php' );

			if ( isset( $entities_data['entities'][ $entity ] ) ) {
				// Try to find the line in entities.json for a nicer reference.
				$line = 0;
				if ( preg_match( '/"' . preg_quote( $entity, '/' ) . '"\s*:/', $entities_raw, $m, PREG_OFFSET_CAPTURE ) ) {
					$line = $this->offset_to_line( $entities_raw, $m[0][1] );
				}
				$findings[] = [
					'file'    => 'config/entities.json',
					'line'    => $line,
					'message' => sprintf(
						'Entity "%s" is defined in both config/entities.json and config/manifests/%s.php. The manifest is the source of truth; remove from entities.json.',
						$entity,
						$entity
					),
				];
			}
		}

		return $findings;
	}

	/**
	 * Check 1: every wp_get_ability('X')/wp_has_ability('X') call in PHP
	 * resolves to an ability declared in config/abilities.json.
	 *
	 * Caught in audit: rest-controller calling shelter-reports/donor-summary
	 * and shelter-reports/annual-statement (both undeclared).
	 *
	 * @param array<string, mixed> $abilities_config Parsed abilities.json.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_ability_references( array $abilities_config ): array {
		$declared = array_keys( $abilities_config['abilities'] ?? [] );

		$findings = [];
		$pattern  = '/wp_(?:get|has)_ability\s*\(\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $this->iter_php_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}
			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[1] as $match ) {
				[ $name, $offset ] = $match;
				if ( in_array( $name, $declared, true ) ) {
					continue;
				}
				$findings[] = [
					'file'    => $this->relative_path( $file ),
					'line'    => $this->offset_to_line( $contents, $offset ),
					'message' => sprintf(
						'Reference to unregistered ability "%s" (not declared in config/abilities.json).',
						$name
					),
				];
			}
		}

		return $findings;
	}

	/**
	 * Check 2: every `ability` id in config/products.json resolves to a
	 * declared ability.
	 *
	 * @param array<string, mixed> $products_config  Parsed products.json.
	 * @param array<string, mixed> $abilities_config Parsed abilities.json.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_product_ability_ids( array $products_config, array $abilities_config ): array {
		$declared = array_keys( $abilities_config['abilities'] ?? [] );

		$findings = [];
		foreach ( ( $products_config['products'] ?? [] ) as $sku_prefix => $product ) {
			$ability_id = $product['ability'] ?? null;
			if ( null === $ability_id ) {
				continue;
			}
			if ( in_array( $ability_id, $declared, true ) ) {
				continue;
			}
			$findings[] = [
				'file'    => 'config/products.json',
				'line'    => 0,
				'message' => sprintf(
					'Product "%s" references ability "%s" which is not declared in config/abilities.json.',
					$sku_prefix,
					$ability_id
				),
			];
		}

		return $findings;
	}

	/**
	 * Check 3: every `input_mapping` target in products.json appears in
	 * the referenced ability's input_schema properties.
	 *
	 * Caught in audit: shelter-memberships-business products.json had no
	 * mapping for logo_attachment_id — uploads silently dropped. Also
	 * surfaces the opposite: input_mapping targets not declared by the
	 * ability's schema (likely typos or stale fields).
	 *
	 * @param array<string, mixed> $products_config  Parsed products.json.
	 * @param array<string, mixed> $abilities_config Parsed abilities.json.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_product_input_mappings( array $products_config, array $abilities_config ): array {
		$findings = [];

		foreach ( ( $products_config['products'] ?? [] ) as $sku_prefix => $product ) {
			$ability_id = $product['ability'] ?? null;
			$mapping    = $product['input_mapping'] ?? [];
			if ( null === $ability_id || empty( $mapping ) ) {
				continue;
			}

			$ability = $abilities_config['abilities'][ $ability_id ] ?? null;
			if ( null === $ability ) {
				continue;
			}

			$schema_props = $ability['input_schema']['properties'] ?? [];
			if ( empty( $schema_props ) ) {
				continue;
			}

			foreach ( array_keys( $mapping ) as $target ) {
				if ( isset( $schema_props[ $target ] ) ) {
					continue;
				}
				$findings[] = [
					'file'    => 'config/products.json',
					'line'    => 0,
					'message' => sprintf(
						'Product "%s" input_mapping target "%s" not declared in %s input_schema.',
						$sku_prefix,
						$target,
						$ability_id
					),
				];
			}
		}

		return $findings;
	}

	/**
	 * Hooks documented as third-party extension points — fired but
	 * intentionally not consumed by any built-in code. Exempt from the
	 * "producer without listener" check.
	 *
	 * @var array<int, string>
	 */
	private const EXTENSION_POINT_HOOKS = [
		'starter_shelter_legacy_record_updated',
		'starter_shelter_legacy_order_synced',
		'starter_shelter_donor_address_updated',
		'starter_shelter_donor_profile_updated',
		'starter_shelter_renewal_reminders_processed',
		'starter_shelter_renewal_reminder_sent',
	];

	/**
	 * Check 4: every do_action('starter_shelter_X') has at least one
	 * matching add_action() listener (or dynamic listener via emails.json
	 * trigger_hook), and every add_action() listener has at least one
	 * matching producer.
	 *
	 * Caught in audit: dead listener for starter_shelter_email_sent (no
	 * producer); orphan producer starter_shelter_memorial_family_notification
	 * (no email subscriber).
	 *
	 * @param array<string, mixed> $emails_config Parsed emails.json — its
	 *                                            `trigger_hook` entries are
	 *                                            dynamic listeners that the
	 *                                            regex below can't detect.
	 * @return array<int, array{file: string, line: int, message: string}>
	 */
	private function check_domain_action_hooks( array $emails_config ): array {
		$producers = [];
		$listeners = [];

		// Dynamic listeners: emails.json trigger_hook entries are
		// add_action()-ed at runtime by Config_Email::__construct.
		foreach ( ( $emails_config['emails'] ?? [] ) as $email_id => $cfg ) {
			$hook = $cfg['trigger_hook'] ?? null;
			if ( $hook ) {
				$listeners[ $hook ][] = [
					'file' => 'config/emails.json',
					'line' => 0,
				];
			}
		}

		$do_pattern  = '/do_action\s*\(\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/';
		$add_pattern = '/add_action\s*\(\s*[\'"](starter_shelter_[a-z0-9_]+)[\'"]/';

		foreach ( $this->iter_php_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}

			if ( preg_match_all( $do_pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[1] as $match ) {
					[ $hook, $offset ] = $match;
					$producers[ $hook ][] = [
						'file' => $this->relative_path( $file ),
						'line' => $this->offset_to_line( $contents, $offset ),
					];
				}
			}

			if ( preg_match_all( $add_pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[1] as $match ) {
					[ $hook, $offset ] = $match;
					$listeners[ $hook ][] = [
						'file' => $this->relative_path( $file ),
						'line' => $this->offset_to_line( $contents, $offset ),
					];
				}
			}
		}

		$findings = [];

		// Producers with no listeners. Email-template extension-point hooks
		// (suffixes _email_footer and _email_content) are documented escape
		// hatches for third-party plugins; not having a built-in listener is
		// expected. The named hooks in EXTENSION_POINT_HOOKS are similarly
		// documented per-event extension points.
		foreach ( $producers as $hook => $sites ) {
			if ( ! empty( $listeners[ $hook ] ) ) {
				continue;
			}
			if ( str_ends_with( $hook, '_email_footer' ) || str_ends_with( $hook, '_email_content' ) ) {
				continue;
			}
			if ( in_array( $hook, self::EXTENSION_POINT_HOOKS, true ) ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$findings[] = [
					'file'    => $site['file'],
					'line'    => $site['line'],
					'message' => sprintf( 'do_action("%s") has no add_action listener anywhere.', $hook ),
				];
			}
		}

		// Listeners with no producers.
		foreach ( $listeners as $hook => $sites ) {
			if ( ! empty( $producers[ $hook ] ) ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$findings[] = [
					'file'    => $site['file'],
					'line'    => $site['line'],
					'message' => sprintf( 'add_action("%s") listens for a hook nothing fires.', $hook ),
				];
			}
		}

		return $findings;
	}

	/**
	 * Load and parse a JSON config file.
	 *
	 * @param string $relative Path relative to plugin root.
	 * @return array<string, mixed>
	 */
	private function load_json( string $relative ): array {
		$path = STARTER_SHELTER_PATH . $relative;
		if ( ! is_file( $path ) ) {
			WP_CLI::error( sprintf( 'Required config missing: %s', $relative ) );
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			WP_CLI::error( sprintf( 'Could not read: %s', $relative ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( 'Invalid JSON: %s', $relative ) );
		}
		return $data;
	}

	/**
	 * Iterate PHP files under includes/ and templates/. Skips vendor/,
	 * node_modules/, and the validator's own file (whose PHPdoc contains
	 * literal `wp_get_ability(...)` examples that would self-match).
	 *
	 * @return \Generator<string>
	 */
	private function iter_php_files(): \Generator {
		$self = __FILE__;
		foreach ( [ 'includes', 'templates' ] as $sub ) {
			$root = STARTER_SHELTER_PATH . $sub;
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iter as $file ) {
				$path = $file->getPathname();
				if ( '.php' !== substr( $path, -4 ) ) {
					continue;
				}
				if ( str_contains( $path, '/vendor/' ) || str_contains( $path, '/node_modules/' ) ) {
					continue;
				}
				if ( $path === $self ) {
					continue;
				}
				yield $path;
			}
		}
	}

	/**
	 * Convert a string-offset to a 1-indexed line number.
	 *
	 * @param string $haystack File contents.
	 * @param int    $offset   Byte offset of match.
	 * @return int Line number.
	 */
	private function offset_to_line( string $haystack, int $offset ): int {
		return substr_count( $haystack, "\n", 0, $offset ) + 1;
	}

	/**
	 * Make a path relative to the plugin root for nicer output.
	 *
	 * @param string $absolute Absolute filesystem path.
	 * @return string Relative path.
	 */
	private function relative_path( string $absolute ): string {
		$prefix = STARTER_SHELTER_PATH;
		return str_starts_with( $absolute, $prefix )
			? substr( $absolute, strlen( $prefix ) )
			: $absolute;
	}

	/**
	 * Emit findings and set the appropriate exit status.
	 *
	 * @param array<int, array{file: string, line: int, message: string}> $findings
	 * @param string $format Output format: human or json.
	 */
	private function emit( array $findings, string $format ): void {
		if ( 'json' === $format ) {
			WP_CLI::print_value( $findings, [ 'format' => 'json' ] );
			if ( ! empty( $findings ) ) {
				exit( 1 );
			}
			return;
		}

		if ( empty( $findings ) ) {
			WP_CLI::success( 'No contract violations found.' );
			return;
		}

		foreach ( $findings as $f ) {
			WP_CLI::line( sprintf(
				'%s:%d  %s',
				$f['file'],
				$f['line'],
				$f['message']
			) );
		}
		WP_CLI::error( sprintf( '%d contract violation(s) found.', count( $findings ) ) );
	}
}
