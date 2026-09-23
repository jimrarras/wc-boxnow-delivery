<?php
namespace WC_BoxNow_Tests\Unit;

/**
 * The translation files in languages/ against each other: the template from
 * bin/make-pot.php, the Greek catalogue and the .mo that
 * bin/make-mo.php compiles from it. The Greek admin, order notes and
 * customer emails come from these files.
 */
class TranslationsTest extends TestCase {

    private function languages(): string {
        return dirname( __DIR__, 2 ) . '/languages/';
    }

    /**
     * Entries of a .po or .pot file, keyed by msgctxt, msgid and
     * msgid_plural, with every string unescaped. The header is left out.
     *
     * @return array<string, array<string, string>>
     */
    private function parsePo( string $file ): array {
        $entries = array();
        $entry   = array();
        $field   = null;
        $lines   = explode( "\n", str_replace( "\r\n", "\n", file_get_contents( $this->languages() . $file ) ) );
        $lines[] = '';

        foreach ( $lines as $line ) {
            if ( '' === trim( $line ) ) {
                if ( isset( $entry['msgid'] ) && '' !== $entry['msgid'] ) {
                    $key             = ( $entry['msgctxt'] ?? '' ) . "\x04" . $entry['msgid'] . "\x04" . ( $entry['msgid_plural'] ?? '' );
                    $entries[ $key ] = $entry;
                }
                $entry = array();
                $field = null;
                continue;
            }
            if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr\[\d\]|msgstr) "(.*)"$/', $line, $m ) ) {
                $field           = $m[1];
                $entry[ $field ] = $this->unescape( $m[2] );
            } elseif ( null !== $field && preg_match( '/^"(.*)"$/', $line, $m ) ) {
                $entry[ $field ] .= $this->unescape( $m[1] );
            }
        }

        return $entries;
    }

    private function unescape( string $text ): string {
        return strtr( $text, array( '\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t" ) );
    }

    /**
     * @return string[] The printf placeholders of a string, sorted.
     */
    private function placeholders( string $text ): array {
        preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $m );
        sort( $m[0] );
        return $m[0];
    }

    public function test_every_template_string_has_a_greek_translation(): void {
        $template = $this->parsePo( 'wc-boxnow-delivery.pot' );
        $greek    = $this->parsePo( 'wc-boxnow-delivery-el.po' );

        $this->assertNotEmpty( $template );
        foreach ( $template as $key => $entry ) {
            $this->assertArrayHasKey( $key, $greek, 'No Greek entry for: ' . $entry['msgid'] );
            if ( isset( $entry['msgid_plural'] ) ) {
                $this->assertNotSame( '', $greek[ $key ]['msgstr[0]'] ?? '', 'Untranslated: ' . $entry['msgid'] );
                $this->assertNotSame( '', $greek[ $key ]['msgstr[1]'] ?? '', 'Untranslated plural: ' . $entry['msgid_plural'] );
            } else {
                $this->assertNotSame( '', $greek[ $key ]['msgstr'] ?? '', 'Untranslated: ' . $entry['msgid'] );
            }
        }
    }

    public function test_greek_catalogue_holds_no_string_the_template_lacks(): void {
        $obsolete = array_diff_key( $this->parsePo( 'wc-boxnow-delivery-el.po' ), $this->parsePo( 'wc-boxnow-delivery.pot' ) );

        $this->assertSame( array(), array_column( $obsolete, 'msgid' ) );
    }

    /** A lost or extra placeholder breaks sprintf() at run time. */
    public function test_greek_translations_keep_every_placeholder(): void {
        foreach ( $this->parsePo( 'wc-boxnow-delivery-el.po' ) as $entry ) {
            if ( isset( $entry['msgid_plural'] ) ) {
                $this->assertSame( $this->placeholders( $entry['msgid'] ), $this->placeholders( $entry['msgstr[0]'] ), $entry['msgid'] );
                $this->assertSame( $this->placeholders( $entry['msgid_plural'] ), $this->placeholders( $entry['msgstr[1]'] ), $entry['msgid_plural'] );
            } else {
                $this->assertSame( $this->placeholders( $entry['msgid'] ), $this->placeholders( $entry['msgstr'] ), $entry['msgid'] );
            }
        }
    }

    /**
     * WordPress loads the .mo, so it must be compiled from the current .po.
     * bin/make-mo.php keys a plural entry by its singular and joins the
     * plural forms with a null byte. The header must also satisfy the POMO
     * MO loader of WordPress 5.8 to 6.4 (MO::import_from_reader()), which
     * measures the translations table up to the hash table offset and
     * rejects the whole file unless that is 8 x count.
     */
    public function test_compiled_greek_catalogue_matches_the_po(): void {
        $expected = array();
        foreach ( $this->parsePo( 'wc-boxnow-delivery-el.po' ) as $entry ) {
            $original              = ( isset( $entry['msgctxt'] ) ? $entry['msgctxt'] . "\x04" : '' ) . $entry['msgid'];
            $expected[ $original ] = isset( $entry['msgid_plural'] ) ? $entry['msgstr[0]'] . "\0" . $entry['msgstr[1]'] : $entry['msgstr'];
        }

        $mo = file_get_contents( $this->languages() . 'wc-boxnow-delivery-el.mo' );
        $this->assertSame( 0x950412de, unpack( 'V', substr( $mo, 0, 4 ) )[1] );
        list( $revision, $count, $originals, $translations, $hash_size, $hash_offset ) = array_values( unpack( 'V6', substr( $mo, 4, 24 ) ) );

        $this->assertSame( 0, $revision );
        $this->assertSame( 8 * $count, $translations - $originals );
        $this->assertSame( 8 * $count, $hash_offset - $translations, 'Hash table offset must follow the translations table.' );

        $compiled = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $o = unpack( 'Vlength/Voffset', substr( $mo, $originals + 8 * $i, 8 ) );
            $t = unpack( 'Vlength/Voffset', substr( $mo, $translations + 8 * $i, 8 ) );
            // POMO reads the strings from the end of the hash table on.
            $this->assertGreaterThanOrEqual( $hash_offset + 4 * $hash_size, min( $o['offset'], $t['offset'] ) );
            $compiled[ substr( $mo, $o['offset'], $o['length'] ) ] = substr( $mo, $t['offset'], $t['length'] );
        }

        $this->assertArrayHasKey( '', $compiled, 'The header entry.' );
        unset( $compiled[''] );
        ksort( $expected );
        ksort( $compiled );
        $this->assertSame( $expected, $compiled );
    }
}
