<?php
/**
 * Dev tool: compile a .po file into a GNU .mo file.
 *
 * Writes a little-endian .mo (magic 0x950412de), includes the header entry
 * (msgid ""), joins a msgctxt with the msgid using \x04, joins plural forms
 * with \0, and sorts entries by the original string, as the binary format
 * requires.
 *
 * Usage: php bin/make-mo.php languages/wc-boxnow-delivery-el.po [output.mo]
 *
 * Not part of the release zip: build.sh only copies the plugin's runtime
 * folders, so this script (and bin/make-pot.php) stays out of it.
 */

$po_path = $argv[1] ?? null;

if ( null === $po_path || ! is_file( $po_path ) ) {
    fwrite( STDERR, "Usage: php bin/make-mo.php <input.po> [output.mo]\n" );
    exit( 1 );
}

$mo_path = $argv[2] ?? preg_replace( '/\.po$/', '.mo', $po_path );

/**
 * Reverse .po string escaping.
 *
 * @param string $text Escaped text between the quotes.
 * @return string
 */
function wc_boxnow_mo_unescape( $text ) {
    $out = '';
    $len = strlen( $text );
    for ( $i = 0; $i < $len; $i++ ) {
        $char = $text[ $i ];
        if ( '\\' === $char && $i + 1 < $len ) {
            $next = $text[ $i + 1 ];
            switch ( $next ) {
                case 'n':
                    $out .= "\n";
                    $i++;
                    break;
                case 't':
                    $out .= "\t";
                    $i++;
                    break;
                case 'r':
                    $out .= "\r";
                    $i++;
                    break;
                case '"':
                    $out .= '"';
                    $i++;
                    break;
                case '\\':
                    $out .= '\\';
                    $i++;
                    break;
                default:
                    $out .= $char;
            }
        } else {
            $out .= $char;
        }
    }
    return $out;
}

/**
 * Parse a .po file into a list of entries.
 *
 * @param string $content Raw file content.
 * @return array List of ['msgctxt'=>?, 'msgid'=>, 'msgid_plural'=>?, 'msgstr'=>?, 'msgstr_plural'=>?]
 */
function wc_boxnow_mo_parse_po( $content ) {
    $lines    = preg_split( '/\r\n|\n|\r/', $content );
    $entries  = array();
    $current  = null;
    $last_key = null;

    $flush = function () use ( &$current, &$entries ) {
        if ( null !== $current && array_key_exists( 'msgid', $current ) ) {
            $entries[] = $current;
        }
        $current = null;
    };

    foreach ( $lines as $line ) {
        $trimmed = trim( $line );

        if ( '' === $trimmed ) {
            $flush();
            $last_key = null;
            continue;
        }

        if ( '#' === $trimmed[0] ) {
            continue;
        }

        if ( preg_match( '/^msgctxt\s+"(.*)"$/s', $trimmed, $m ) ) {
            $current            = $current ?? array();
            $current['msgctxt'] = wc_boxnow_mo_unescape( $m[1] );
            $last_key           = 'msgctxt';
            continue;
        }

        if ( preg_match( '/^msgid_plural\s+"(.*)"$/s', $trimmed, $m ) ) {
            $current                 = $current ?? array();
            $current['msgid_plural'] = wc_boxnow_mo_unescape( $m[1] );
            $last_key                = 'msgid_plural';
            continue;
        }

        if ( preg_match( '/^msgid\s+"(.*)"$/s', $trimmed, $m ) ) {
            $current          = $current ?? array();
            $current['msgid'] = wc_boxnow_mo_unescape( $m[1] );
            $last_key         = 'msgid';
            continue;
        }

        if ( preg_match( '/^msgstr\[(\d+)\]\s+"(.*)"$/s', $trimmed, $m ) ) {
            $current                                    = $current ?? array();
            $current['msgstr_plural'][ (int) $m[1] ]    = wc_boxnow_mo_unescape( $m[2] );
            $last_key                                   = array( 'msgstr_plural', (int) $m[1] );
            continue;
        }

        if ( preg_match( '/^msgstr\s+"(.*)"$/s', $trimmed, $m ) ) {
            $current           = $current ?? array();
            $current['msgstr'] = wc_boxnow_mo_unescape( $m[1] );
            $last_key          = 'msgstr';
            continue;
        }

        if ( preg_match( '/^"(.*)"$/s', $trimmed, $m ) ) {
            $text = wc_boxnow_mo_unescape( $m[1] );
            if ( is_array( $last_key ) && 'msgstr_plural' === $last_key[0] ) {
                $current['msgstr_plural'][ $last_key[1] ] .= $text;
            } elseif ( is_string( $last_key ) && null !== $current ) {
                $current[ $last_key ] .= $text;
            }
            continue;
        }
        // Anything else (stray text) is ignored.
    }

    $flush();

    return $entries;
}

/**
 * Pack entries into the GNU .mo binary format.
 *
 * @param array $entries List of ['key' => original string, 'value' => translated string].
 * @return string Binary .mo content.
 */
function wc_boxnow_mo_build( array $entries ) {
    usort( $entries, function ( $a, $b ) {
        return strcmp( $a['key'], $b['key'] );
    } );

    $count = count( $entries );

    $header_size      = 28; // 7 x uint32
    $orig_table_size  = $count * 8;
    $trans_table_size = $count * 8;

    $orig_table  = '';
    $orig_data   = '';
    $orig_offset = $header_size + $orig_table_size + $trans_table_size;

    foreach ( $entries as $entry ) {
        $len          = strlen( $entry['key'] );
        $orig_table  .= pack( 'VV', $len, $orig_offset );
        $orig_data   .= $entry['key'] . "\0";
        $orig_offset += $len + 1;
    }

    $trans_table  = '';
    $trans_data   = '';
    $trans_offset = $orig_offset;

    foreach ( $entries as $entry ) {
        $len           = strlen( $entry['value'] );
        $trans_table  .= pack( 'VV', $len, $trans_offset );
        $trans_data   .= $entry['value'] . "\0";
        $trans_offset += $len + 1;
    }

    $orig_table_offset  = $header_size;
    $trans_table_offset = $header_size + $orig_table_size;

    // No hash table is written, but WordPress's POMO MO loader (before 6.5)
    // measures the translations table up to the hash table offset and
    // rejects the file unless that is 8 x count. An empty table where the
    // strings start satisfies it and every other reader.
    $hash_table_offset = $header_size + $orig_table_size + $trans_table_size;

    $header = pack(
        'VVVVVVV',
        0x950412de, // Magic number, little-endian.
        0,          // Format revision.
        $count,     // Number of strings.
        $orig_table_offset,
        $trans_table_offset,
        0,          // Hash table size (no table).
        $hash_table_offset
    );

    return $header . $orig_table . $trans_table . $orig_data . $trans_data;
}

// ── Run ─────────────────────────────────────────────────────────────

$po_entries = wc_boxnow_mo_parse_po( file_get_contents( $po_path ) );

$mo_entries = array();
foreach ( $po_entries as $entry ) {
    $msgid = $entry['msgid'] ?? '';
    $is_header = ( '' === $msgid );

    if ( isset( $entry['msgid_plural'] ) ) {
        $plural = $entry['msgstr_plural'] ?? array();
        ksort( $plural );
        $value = implode( "\0", $plural );
        if ( '' === trim( implode( '', $plural ) ) && ! $is_header ) {
            continue; // Untranslated plural entry.
        }
    } else {
        $value = $entry['msgstr'] ?? '';
        if ( '' === $value && ! $is_header ) {
            continue; // Untranslated entry.
        }
    }

    $key = ( isset( $entry['msgctxt'] ) ? $entry['msgctxt'] . "\x04" : '' ) . $msgid;

    $mo_entries[] = array(
        'key'   => $key,
        'value' => $value,
    );
}

file_put_contents( $mo_path, wc_boxnow_mo_build( $mo_entries ) );

fwrite( STDOUT, sprintf( "Wrote %d entries to %s\n", count( $mo_entries ), $mo_path ) );
