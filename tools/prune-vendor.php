<?php
/**
 * Potatura del vendor per la distribuzione del plugin: rimuove test, sample
 * e i font mPDF non utilizzati (si tengono i DejaVu, usati dal tema di serie
 * e come default mPDF). Agganciato a post-install-cmd/post-update-cmd così
 * ogni composer install produce lo stesso vendor snello che va in git.
 */

declare(strict_types=1);

$vendor = dirname( __DIR__ ) . '/vendor';
if ( ! is_dir( $vendor ) ) {
	exit( 0 );
}

/** Cartelle da rimuovere integralmente. */
$remove = array(
	'mpdf/mpdf/tests',
	'smalot/pdfparser/samples',
	'smalot/pdfparser/tests',
	'smalot/pdfparser/doc',
	'smalot/pdfparser/dev-tools',
	'setasign/fpdi/tests',
	'setasign/fpdi/local-tests',
	'woocommerce/action-scheduler/tests',
);

$rrmdir = static function ( string $dir ) use ( &$rrmdir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( array_diff( scandir( $dir ) ?: array(), array( '.', '..' ) ) as $item ) {
		$path = $dir . '/' . $item;
		is_dir( $path ) && ! is_link( $path ) ? $rrmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
};

// Repository git annidati (installazioni da source): mai in un vendor
// distribuito, e git li tratterebbe come submodule.
$git_dirs = glob( $vendor . '/*/*/.git', GLOB_ONLYDIR ) ?: array();
foreach ( $git_dirs as $git_dir ) {
	$remove[] = substr( $git_dir, strlen( $vendor ) + 1 );
}

foreach ( $remove as $relative ) {
	$path = $vendor . '/' . $relative;
	if ( is_dir( $path ) ) {
		$rrmdir( $path );
		echo "prune-vendor: rimosso {$relative}\n";
	}
}

// Font mPDF: si tengono solo i DejaVu (default mPDF + tema di serie).
// I temi con font propri li portano nel proprio pacchetto (fonts/).
$ttfonts = $vendor . '/mpdf/mpdf/ttfonts';
if ( is_dir( $ttfonts ) ) {
	foreach ( array_diff( scandir( $ttfonts ) ?: array(), array( '.', '..' ) ) as $file ) {
		if ( ! str_starts_with( $file, 'DejaVu' ) && '.htaccess' !== $file ) {
			unlink( $ttfonts . '/' . $file );
		}
	}
	echo "prune-vendor: ttfonts ridotti ai DejaVu\n";
}

exit( 0 );
