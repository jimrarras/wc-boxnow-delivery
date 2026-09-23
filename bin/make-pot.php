<?php
/**
 * Dev tool: scan the plugin's PHP files for translatable strings and write
 * languages/wc-boxnow-delivery.pot.
 *
 * Uses token_get_all() rather than regex on raw source, so string literals
 * inside comments or unrelated code never produce a false match.
 *
 * Usage: php bin/make-pot.php
 *
 * Not part of the release zip: build.sh only copies the plugin's runtime
 * folders, so this script (and bin/make-mo.php) stays out of it.
 */

$root       = dirname( __DIR__ );
$domain     = 'wc-boxnow-delivery';
$pot_path   = $root . '/languages/wc-boxnow-delivery.pot';
$version    = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/wc-boxnow-delivery.php' ), $vm ) ? $vm[1] : '';

$excluded_dirs = array( 'vendor', 'tests', 'docs', 'bin', 'languages', '.git', 'node_modules' );

/**
 * Functions this scanner understands, and which of their string-literal
 * arguments (after the trailing text domain is dropped) are the msgid, the
 * msgctxt and the msgid_plural.
 */
$signatures = array(
    '__'          => array( 'msgid' => 0 ),
    '_e'          => array( 'msgid' => 0 ),
    'esc_html__'  => array( 'msgid' => 0 ),
    'esc_html_e'  => array( 'msgid' => 0 ),
    'esc_attr__'  => array( 'msgid' => 0 ),
    'esc_attr_e'  => array( 'msgid' => 0 ),
    '_x'          => array( 'msgid' => 0, 'msgctxt' => 1 ),
    '_n'          => array( 'msgid' => 0, 'msgid_plural' => 1 ),
    '_n_noop'     => array( 'msgid' => 0, 'msgid_plural' => 1 ),
);

/**
 * @param string $file Absolute path.
 * @return bool
 */
function wc_boxnow_pot_is_excluded( $file, $root, $excluded_dirs ) {
    $relative = ltrim( str_replace( '\\', '/', substr( $file, strlen( $root ) ) ), '/' );
    foreach ( $excluded_dirs as $dir ) {
        if ( 0 === strpos( $relative, $dir . '/' ) ) {
            return true;
        }
    }
    return false;
}

/**
 * @return string[] Absolute paths to *.php files, excluding $excluded_dirs.
 */
function wc_boxnow_pot_find_php_files( $root, $excluded_dirs ) {
    $files    = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $info ) {
        if ( ! $info->isFile() || 'php' !== strtolower( $info->getExtension() ) ) {
            continue;
        }
        $path = $info->getPathname();
        if ( wc_boxnow_pot_is_excluded( $path, $root, $excluded_dirs ) ) {
            continue;
        }
        $files[] = $path;
    }

    sort( $files );
    return $files;
}

/**
 * Decode a PHP single- or double-quoted string literal token (with its
 * surrounding quotes) into its raw text.
 *
 * @param string $token Raw token text, including quotes.
 * @return string
 */
function wc_boxnow_pot_decode_string( $token ) {
    $quote = $token[0];
    $body  = substr( $token, 1, -1 );

    if ( "'" === $quote ) {
        return str_replace( array( "\\\\", "\\'" ), array( '\\', "'" ), $body );
    }

    $map = array(
        '\\\\' => '\\',
        '\\"'  => '"',
        '\\n'  => "\n",
        '\\r'  => "\r",
        '\\t'  => "\t",
        '\\$'  => '$',
    );

    return strtr( $body, $map );
}

/**
 * Escape a string for a .pot msgid/msgstr value.
 *
 * @param string $text Raw text.
 * @return string
 */
function wc_boxnow_pot_escape( $text ) {
    $text = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $text );
    $text = str_replace( "\n", '\\n"' . "\n" . '"', $text );
    return $text;
}

/**
 * @param string $file       Absolute file path.
 * @param string $root       Plugin root.
 * @param array  $signatures Function name => argument map.
 * @param string $domain     Text domain that must be the last string argument.
 * @return array List of entries: msgid, msgctxt, msgid_plural, file, line.
 */
function wc_boxnow_pot_scan_file( $file, $root, $signatures, $domain ) {
    $entries = array();
    $source  = file_get_contents( $file );
    if ( false === $source ) {
        return $entries;
    }

    $relative = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
    $tokens   = token_get_all( $source );
    $count    = count( $tokens );

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];

        if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $signatures[ $token[1] ] ) ) {
            continue;
        }

        // The previous significant token must not be -> or :: (a method call
        // or a differently namespaced symbol, not the WordPress function).
        $prev = null;
        for ( $p = $i - 1; $p >= 0; $p-- ) {
            if ( is_array( $tokens[ $p ] ) && T_WHITESPACE === $tokens[ $p ][0] ) {
                continue;
            }
            $prev = $tokens[ $p ];
            break;
        }
        if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
            continue;
        }
        if ( is_string( $prev ) && '->' === $prev ) {
            continue;
        }

        // The next significant token must be an opening parenthesis.
        $j = $i + 1;
        while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
            $j++;
        }
        if ( $j >= $count || '(' !== $tokens[ $j ] ) {
            continue;
        }

        $line     = $token[2];
        $function = $token[1];

        // Walk to the matching closing parenthesis, collecting string literals.
        // An argument that joins a literal with anything else (for example
        // $label . ' <span class="count">(%s)</span>') has no msgid that can be
        // known here, so the whole call is left out.
        $depth     = 0;
        $strings   = array();
        $arg_lit   = 0;
        $arg_other = 0;
        $mixed     = false;
        for ( $k = $j; $k < $count; $k++ ) {
            $t = $tokens[ $k ];
            if ( '(' === $t ) {
                $depth++;
                continue;
            }
            if ( ')' === $t ) {
                $depth--;
                if ( 0 === $depth ) {
                    $mixed = $mixed || ( $arg_lit > 0 && $arg_other > 0 );
                    break;
                }
                continue;
            }
            if ( 1 === $depth && ',' === $t ) {
                $mixed     = $mixed || ( $arg_lit > 0 && $arg_other > 0 );
                $arg_lit   = 0;
                $arg_other = 0;
                continue;
            }
            if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                continue;
            }
            if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
                $strings[] = wc_boxnow_pot_decode_string( $t[1] );
                if ( 1 === $depth ) {
                    $arg_lit++;
                }
            } elseif ( 1 === $depth ) {
                $arg_other++;
            }
        }

        if ( $mixed || empty( $strings ) || $domain !== end( $strings ) ) {
            continue;
        }
        array_pop( $strings ); // Drop the text domain.

        $map   = $signatures[ $function ];
        $entry = array(
            'msgid'        => $strings[ $map['msgid'] ] ?? null,
            'msgctxt'      => isset( $map['msgctxt'] ) ? ( $strings[ $map['msgctxt'] ] ?? null ) : null,
            'msgid_plural' => isset( $map['msgid_plural'] ) ? ( $strings[ $map['msgid_plural'] ] ?? null ) : null,
            'file'         => $relative,
            'line'         => $line,
        );

        if ( null === $entry['msgid'] ) {
            continue;
        }

        $entries[] = $entry;
    }

    return $entries;
}

// ── Run the scan ───────────────────────────────────────────────────

$files = wc_boxnow_pot_find_php_files( $root, $excluded_dirs );

$catalog = array(); // key => array( msgid, msgctxt, msgid_plural, refs => array() )

foreach ( $files as $file ) {
    foreach ( wc_boxnow_pot_scan_file( $file, $root, $signatures, $domain ) as $entry ) {
        $key = ( $entry['msgctxt'] ?? '' ) . "\x04" . $entry['msgid'] . "\x04" . ( $entry['msgid_plural'] ?? '' );

        if ( ! isset( $catalog[ $key ] ) ) {
            $catalog[ $key ] = array(
                'msgid'        => $entry['msgid'],
                'msgctxt'      => $entry['msgctxt'],
                'msgid_plural' => $entry['msgid_plural'],
                'refs'         => array(),
            );
        }

        $catalog[ $key ]['refs'][] = $entry['file'] . ':' . $entry['line'];
    }
}

// ── Write the .pot file ──────────────────────────────────────────────

$lines   = array();
$lines[] = '# Translation template for BOX NOW Delivery for WooCommerce.';
$lines[] = '# Copyright (C) ' . gmdate( 'Y' ) . ' Dimitrios Rarras';
$lines[] = '# This file is distributed under the GPL-2.0-or-later.';
$lines[] = 'msgid ""';
$lines[] = 'msgstr ""';
$lines[] = '"Project-Id-Version: BOX NOW Delivery for WooCommerce ' . $version . '\n"';
$lines[] = '"Report-Msgid-Bugs-To: https://github.com/jimrarras/wc-boxnow-delivery/issues\n"';
$lines[] = '"POT-Creation-Date: ' . gmdate( 'Y-m-d\TH:i:s' ) . '+00:00\n"';
$lines[] = '"MIME-Version: 1.0\n"';
$lines[] = '"Content-Type: text/plain; charset=UTF-8\n"';
$lines[] = '"Content-Transfer-Encoding: 8bit\n"';
$lines[] = '"PO-Revision-Date: \n"';
$lines[] = '"Last-Translator: \n"';
$lines[] = '"Language-Team: \n"';
$lines[] = '"X-Generator: bin/make-pot.php\n"';
$lines[] = '"Language: en\n"';
$lines[] = '"Plural-Forms: nplurals=2; plural=(n != 1);\n"';
$lines[] = '';

foreach ( $catalog as $entry ) {
    foreach ( $entry['refs'] as $ref ) {
        $lines[] = '#: ' . $ref;
    }
    if ( null !== $entry['msgid_plural'] ) {
        $lines[] = '#, php-format';
    }
    if ( null !== $entry['msgctxt'] ) {
        $lines[] = 'msgctxt "' . wc_boxnow_pot_escape( $entry['msgctxt'] ) . '"';
    }
    $lines[] = 'msgid "' . wc_boxnow_pot_escape( $entry['msgid'] ) . '"';
    if ( null !== $entry['msgid_plural'] ) {
        $lines[] = 'msgid_plural "' . wc_boxnow_pot_escape( $entry['msgid_plural'] ) . '"';
        $lines[] = 'msgstr[0] ""';
        $lines[] = 'msgstr[1] ""';
    } else {
        $lines[] = 'msgstr ""';
    }
    $lines[] = '';
}

if ( ! is_dir( dirname( $pot_path ) ) ) {
    mkdir( dirname( $pot_path ), 0777, true );
}

file_put_contents( $pot_path, implode( "\n", $lines ) . "\n" );

fwrite( STDOUT, sprintf( "Wrote %d entries to %s\n", count( $catalog ), $pot_path ) );
