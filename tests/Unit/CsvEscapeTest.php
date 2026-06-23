<?php
/**
 * Unit tests for CSV formula-injection neutralization.
 *
 * esc_csv_field() prefixes spreadsheet-formula triggers (= + - @ tab CR) on
 * non-numeric values so an exported donor/campaign name like
 * `=HYPERLINK(...)` can't execute when an admin opens the file. Genuine
 * numbers must pass through unchanged so numeric columns stay numeric.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Starter_Shelter\Helpers\esc_csv_field;
use function Starter_Shelter\Helpers\fputcsv_safe;

final class CsvEscapeTest extends TestCase {

    /**
     * @dataProvider neutralizedProvider
     */
    public function test_formula_triggers_are_neutralized( string $input ): void {
        $this->assertSame( "'" . $input, esc_csv_field( $input ) );
    }

    public function neutralizedProvider(): array {
        return [
            'equals formula'   => [ '=HYPERLINK("//evil","x")' ],
            'plus formula'     => [ '+1+cmd' ],
            'at formula'       => [ '@SUM(A1:A9)' ],
            'minus with text'  => [ '-2+3+cmd|calc' ],
            'tab lead'         => [ "\t=1" ],
            'cr lead'          => [ "\r=1" ],
        ];
    }

    /**
     * @dataProvider untouchedProvider
     */
    public function test_safe_values_pass_through( string $input ): void {
        $this->assertSame( $input, esc_csv_field( $input ) );
    }

    public function untouchedProvider(): array {
        return [
            'plain name'       => [ 'Jamie Rivera' ],
            'email'            => [ 'jamie@example.test' ],
            'positive int'     => [ '1000' ],
            'negative number'  => [ '-5' ],
            'signed number'    => [ '+5' ],
            'decimal'          => [ '3.14' ],
            'currency string'  => [ '$250.00' ],
            'empty'            => [ '' ],
        ];
    }

    public function test_non_string_values_are_stringified(): void {
        $this->assertSame( '42', esc_csv_field( 42 ) );
        $this->assertSame( '', esc_csv_field( null ) );
    }

    public function test_fputcsv_safe_writes_neutralized_row(): void {
        $stream = fopen( 'php://temp', 'r+' );
        fputcsv_safe( $stream, [ 'Jamie', '=cmd|calc', '100' ] );
        rewind( $stream );
        $line = stream_get_contents( $stream );
        fclose( $stream );

        $this->assertStringContainsString( "'=cmd|calc", $line, 'Formula cell is quoted.' );
        $this->assertStringContainsString( 'Jamie', $line );
    }
}
