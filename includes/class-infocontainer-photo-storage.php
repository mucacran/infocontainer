<?php
/**
 * Original image validation and storage outside the web document root.
 *
 * @package InfoContainer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Photo_Storage {
	const MAX_FILE_BYTES       = 10485760; // 10 MiB, subject to smaller server limits.
	const MAX_DIMENSION        = 10000;
	const MAX_PIXELS           = 20000000;
	const MAX_DECODE_BYTES     = 134217728; // 128 MiB.
	const DECODE_RESERVE_BYTES = 33554432; // 32 MiB, in addition to eight bytes/pixel.

	/** Read-only location diagnostics; never creates directories or changes permissions. */
	public function diagnostic() {
		$configured = defined( 'INFOCONTAINER_PRIVATE_ROOT' ) ? INFOCONTAINER_PRIVATE_ROOT : dirname( untrailingslashit( ABSPATH ) ) . '/infocontainer-private';
		$configured = apply_filters( 'infocontainer_private_root', $configured );
		$result = array( 'configured' => is_string( $configured ) ? $this->normalize_path( $configured ) : '', 'exists' => false, 'outside_public' => false, 'resolved' => '', 'ancestor' => '', 'ancestor_readable' => false, 'ancestor_writable' => false, 'links_in_path' => false );
		if ( ! is_string( $configured ) || ! $this->is_absolute( $configured ) || false !== strpos( $configured, "\0" ) ) { return $result; }
		$configured = $this->normalize_path( $configured );
		$probe = $configured;
		while ( ! file_exists( $probe ) ) { $parent = dirname( $probe ); if ( $parent === $probe ) { break; } $probe = $parent; }
		$result['ancestor'] = is_dir( $probe ) && false !== realpath( $probe ) ? $this->normalize_path( realpath( $probe ) ) : '';
		$result['ancestor_readable'] = '' !== $result['ancestor'] && is_readable( $probe );
		$result['ancestor_writable'] = '' !== $result['ancestor'] && is_writable( $probe );
		$result['exists'] = is_dir( $configured );
		$result['resolved'] = false !== realpath( $configured ) ? $this->normalize_path( realpath( $configured ) ) : '';
		$public = array( $this->normalize_path( ABSPATH ) );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) { $public[] = $this->normalize_path( $_SERVER['DOCUMENT_ROOT'] ); }
		$result['outside_public'] = '' !== $result['resolved'] ? $this->is_private( $result['resolved'], $public ) : $this->is_private( $configured, $public );
		for ( $path = $configured; $path !== dirname( $path ); $path = dirname( $path ) ) { if ( is_link( $path ) ) { $result['links_in_path'] = true; break; } }
		return $result;
	}

	/**
	 * Resolve/create the private root and check its actual filesystem location.
	 *
	 * INFOCONTAINER_PRIVATE_ROOT or infocontainer_private_root may configure an
	 * absolute root. Database storage keys stay relative when that root changes.
	 * Symlink targets and Windows case differences are checked on real paths.
	 *
	 * @return string|WP_Error Canonical private directory; never put it in an API response.
	 */
	public function root() {
		$configured = defined( 'INFOCONTAINER_PRIVATE_ROOT' )
			? INFOCONTAINER_PRIVATE_ROOT
			: dirname( untrailingslashit( ABSPATH ) ) . '/infocontainer-private';
		$configured = apply_filters( 'infocontainer_private_root', $configured );
		if ( ! is_string( $configured ) || ! $this->is_absolute( $configured ) || false !== strpos( $configured, "\0" ) ) {
			return $this->configuration_error();
		}
		$configured = $this->normalize_path( $configured );
		if ( preg_match( '#(?:^|/)\.\.?(/|$)#', $configured ) ) {
			return $this->configuration_error();
		}

		$public_roots = array( ABSPATH );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
			$public_roots[] = $_SERVER['DOCUMENT_ROOT'];
		}
		$actual_public_roots = array();
		foreach ( $public_roots as $public_root ) {
			$actual_public = realpath( $public_root );
			if ( false === $actual_public || ! is_dir( $actual_public ) ) {
				return $this->configuration_error();
			}
			$actual_public_roots[] = $this->normalize_path( $actual_public );
		}

		// Resolve existing ancestors before creating anything. A root configured
		// through a public symlink must not cause even a directory to be created.
		$prospective = $this->prospective_path( $configured );
		if ( is_wp_error( $prospective ) || ! $this->is_private( $prospective, $actual_public_roots ) ) {
			return $this->configuration_error();
		}
		if ( ! is_dir( $configured ) && ! @mkdir( $configured, 0700, true ) && ! is_dir( $configured ) ) {
			return $this->configuration_error();
		}
		clearstatcache( true, $configured );
		$actual = realpath( $configured );
		if ( false === $actual || ! is_dir( $actual ) ) {
			return $this->configuration_error();
		}
		$actual = $this->normalize_path( $actual );
		if ( ! $this->is_private( $actual, $actual_public_roots ) || ! $this->secure_directory( $actual ) ) {
			return $this->configuration_error();
		}
		return $actual;
	}

	/**
	 * Validate one genuine HTTP upload without changing its original bytes.
	 *
	 * getimagesize is only a preflight for resource limits; GD decoding remains
	 * mandatory. Fileinfo, extension and WordPress's real-type check must agree.
	 *
	 * @param array $file A single flattened entry from $_FILES.
	 * @return array|WP_Error Metadata plus tmp_name for store().
	 */
	public function validate_upload( array $file ) {
		if ( ! isset( $file['error'] ) || ! is_int( $file['error'] ) ) {
			return $this->error( 'upload_invalid', 'El archivo de subida no es válido.', 400 );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			if ( UPLOAD_ERR_INI_SIZE === $file['error'] || UPLOAD_ERR_FORM_SIZE === $file['error'] ) {
				return $this->error( 'upload_too_large', 'La fotografía supera el límite de subida del servidor.', 413 );
			}
			if ( UPLOAD_ERR_NO_FILE === $file['error'] || UPLOAD_ERR_PARTIAL === $file['error'] ) {
				return $this->error( 'upload_incomplete', 'La solicitud debe incluir un archivo completo en file.', 400 );
			}
			return $this->error( 'upload_failed', 'El servidor no pudo recibir la fotografía.', 503 );
		}
		if ( ! isset( $file['tmp_name'], $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] )
			|| false !== strpos( $file['tmp_name'], "\0" ) || false !== strpos( $file['name'], "\0" )
			|| 1 !== preg_match( '//u', $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $this->error( 'upload_invalid', 'El archivo de subida no es válido.', 400 );
		}

		$size = @filesize( $file['tmp_name'] );
		if ( false === $size || $size < 1 ) {
			return $this->error( 'upload_empty', 'La fotografía está vacía o no pudo recibirse.', 400 );
		}
		$limit = self::MAX_FILE_BYTES;
		foreach ( array( 'upload_max_filesize', 'post_max_size' ) as $directive ) {
			$php_limit = $this->ini_bytes( ini_get( $directive ) );
			if ( $php_limit > 0 ) {
				$limit = min( $limit, $php_limit );
			}
		}
		$wp_limit = (int) wp_max_upload_size();
		if ( $wp_limit > 0 ) {
			$limit = min( $limit, $wp_limit );
		}
		if ( $size > $limit ) {
			return $this->error( 'upload_too_large', 'La fotografía supera el límite de tamaño admitido.', 413 );
		}

		$name      = basename( str_replace( '\\', '/', $file['name'] ) );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$types     = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
		if ( ! isset( $types[ $extension ] ) ) {
			return $this->format_error();
		}
		if ( ! extension_loaded( 'fileinfo' ) || ! function_exists( 'finfo_open' ) || ! function_exists( 'finfo_file' ) ) {
			return $this->error( 'fileinfo_unavailable', 'El servidor necesita Fileinfo para validar fotografías.', 503 );
		}
		$finfo = @finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return $this->error( 'fileinfo_unavailable', 'El servidor necesita Fileinfo para validar fotografías.', 503 );
		}
		$mime = @finfo_file( $finfo, $file['tmp_name'] );
		if ( is_resource( $finfo ) ) {
			finfo_close( $finfo );
		}
		$finfo = null;
		if ( ! is_string( $mime ) || $types[ $extension ] !== $mime ) {
			return $this->format_error();
		}
		$checked = wp_check_filetype_and_ext(
			$file['tmp_name'],
			$name,
			array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' )
		);
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || $checked['type'] !== $mime ) {
			return $this->format_error();
		}

		$decoders = array( 'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp' );
		$decoder  = $decoders[ $mime ];
		if ( ! extension_loaded( 'gd' ) || ! function_exists( $decoder ) || ! function_exists( 'imagesx' )
			|| ! function_exists( 'imagesy' ) || ! function_exists( 'imagedestroy' ) ) {
			return $this->error( 'gd_unavailable', 'El servidor necesita GD con soporte del formato de la fotografía.', 503 );
		}
		$dimensions = @getimagesize( $file['tmp_name'] );
		if ( false === $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return $this->format_error();
		}
		$width  = (int) $dimensions[0];
		$height = (int) $dimensions[1];
		$pixels = $width * $height;
		$decode = $pixels * 8 + self::DECODE_RESERVE_BYTES;
		$memory_limit = $this->ini_bytes( ini_get( 'memory_limit' ) );
		if ( $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $pixels > self::MAX_PIXELS
			|| $decode > self::MAX_DECODE_BYTES
			|| ( $memory_limit > 0 && memory_get_usage( true ) + $decode > $memory_limit ) ) {
			return $this->error( 'image_resources_exceeded', 'Las dimensiones o la memoria necesaria de la fotografía superan los límites admitidos.', 413 );
		}

		$hash = $this->inspect_bytes( $file['tmp_name'], $size );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}
		try {
			$image = @$decoder( $file['tmp_name'] );
		} catch ( Throwable $exception ) {
			return $this->format_error();
		}
		if ( false === $image ) {
			return $this->format_error();
		}
		$decoded_width  = imagesx( $image );
		$decoded_height = imagesy( $image );
		// PHP 7.x exposes a GD resource; PHP 8.x releases GdImage objects itself.
		// imagedestroy(GdImage) is deprecated in PHP 8.5 and can leak PHP paths.
		if ( is_resource( $image ) ) {
			imagedestroy( $image );
		}
		$image = null;
		if ( $decoded_width !== $width || $decoded_height !== $height ) {
			return $this->format_error();
		}

		$original_filename = sanitize_file_name( $name );
		if ( '' === $original_filename ) {
			$original_filename = 'photo.' . $extension;
		}
		preg_match( '/\A.{1,255}/us', $original_filename, $filename_match );
		$original_filename = isset( $filename_match[0] ) ? $filename_match[0] : 'photo.' . $extension;
		return array(
			'tmp_name'          => $file['tmp_name'],
			'original_filename' => $original_filename,
			'mime_type'         => $mime,
			'byte_size'         => (int) $size,
			'width'             => $width,
			'height'            => $height,
			'sha256'            => $hash,
			'extension'         => 'jpeg' === $extension ? 'jpg' : $extension,
		);
	}

	/**
	 * Copy a validated upload to an exclusively created private file.
	 *
	 * @param array $validated Result of validate_upload().
	 * @return string|WP_Error Relative storage key.
	 */
	public function store( array $validated ) {
		if ( ! isset( $validated['tmp_name'], $validated['extension'], $validated['byte_size'], $validated['sha256'] )
			|| ! is_string( $validated['tmp_name'] ) || ! is_uploaded_file( $validated['tmp_name'] )
			|| ! in_array( $validated['extension'], array( 'jpg', 'png', 'webp' ), true )
			|| ! is_int( $validated['byte_size'] ) || $validated['byte_size'] < 1 || $validated['byte_size'] > self::MAX_FILE_BYTES
			|| ! is_string( $validated['sha256'] ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $validated['sha256'] ) ) {
			return $this->error( 'storage_failed', 'No se pudo guardar la fotografía.', 500 );
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$directory = $this->directory( $root, 'photos' );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$random = $this->random_name();
		if ( is_wp_error( $random ) ) {
			return $random;
		}
		$key  = 'photos/' . $random . '.' . $validated['extension'];
		$path = $root . '/' . $key;
		$destination = @fopen( $path, 'x+b' );
		if ( false === $destination ) {
			return $this->error( 'storage_failed', 'No se pudo guardar la fotografía.', 500 );
		}
		$source = @fopen( $validated['tmp_name'], 'rb' );
		$ok     = false !== $source && $this->secure_file( $path ) && $this->verified_file( $root, $path );
		$copied = $ok ? @stream_copy_to_stream( $source, $destination, $validated['byte_size'] + 1 ) : false;
		$flushed = @fflush( $destination );
		if ( false !== $source ) {
			fclose( $source );
		}
		fclose( $destination );
		if ( $ok && $flushed && $copied === $validated['byte_size'] ) {
			$stored_hash = @hash_file( 'sha256', $path );
			if ( is_string( $stored_hash ) && hash_equals( $validated['sha256'], $stored_hash ) ) {
				return $key;
			}
		}
		// Only this exclusively created path can be cleaned up here.
		$cleanup = $this->delete( $key );
		if ( is_wp_error( $cleanup ) ) {
			return $this->error( 'storage_cleanup_failed', 'No se pudo completar ni limpiar la subida. El administrador debe revisar el almacenamiento privado.', 500 );
		}
		return $this->error( 'storage_failed', 'No se pudo guardar la fotografía.', 500 );
	}

	/** Store an identification upload privately until its provider call ends. */
	public function store_temporary( array $validated ) {
		$key = $this->store( $validated );
		if ( is_wp_error( $key ) ) { return $key; }
		$root = $this->root();
		$directory = is_wp_error( $root ) ? $root : $this->directory( $root, 'ai-temp' );
		if ( is_wp_error( $directory ) ) { $this->delete( $key ); return $directory; }
		$temp_key = 'ai-temp/' . basename( $key );
		$source = $this->key_path( $key );
		$target = $this->key_path( $temp_key );
		if ( is_wp_error( $source ) || is_wp_error( $target ) || ! @rename( $source, $target ) || ! $this->verified_file( $root, $target ) ) {
			$this->delete( $key );
			return $this->error( 'storage_failed', 'No se pudo preparar la fotografia temporal.', 500 );
		}
		return $temp_key;
	}

	/** Remove a bounded number of interrupted-run files after a one-hour grace period. */
	public function cleanup_ai_temporaries( $max_files = 20, $minimum_age = 3600 ) {
		$root = $this->root();
		$directory = is_wp_error( $root ) ? $root : $this->directory( $root, 'ai-temp' );
		if ( is_wp_error( $directory ) ) { return $directory; }
		$entries = @scandir( $directory );
		if ( ! is_array( $entries ) ) { return true; }
		$removed = 0;
		foreach ( $entries as $name ) {
			if ( $removed >= max( 1, min( 100, (int) $max_files ) ) || ! preg_match( '/\A[a-f0-9]{64}\.(?:jpg|png|webp)\z/', $name ) ) { continue; }
			$key = 'ai-temp/' . $name; $path = $this->key_path( $key );
			if ( is_wp_error( $path ) || is_link( $path ) ) { continue; }
			$mtime = @filemtime( $path );
			if ( false !== $mtime && $mtime < time() - max( 600, (int) $minimum_age ) && true === $this->delete( $key ) ) { ++$removed; }
		}
		return true;
	}

	/**
	 * Open a contained regular file for authenticated streaming.
	 *
	 * @param string $storage_key Relative database key.
	 * @return resource|WP_Error Caller must close the handle.
	 */
	public function open( $storage_key ) {
		$path = $this->existing_file( $storage_key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return $this->error( 'file_unavailable', 'No se pudo leer la fotografía.', 500 );
		}
		$opened = fstat( $handle );
		$actual = @stat( $path );
		if ( false === $opened || false === $actual || ( $opened['mode'] & 0170000 ) !== 0100000
			|| $opened['dev'] !== $actual['dev'] || $opened['ino'] !== $actual['ino'] || is_link( $path ) ) {
			fclose( $handle );
			return $this->error( 'file_unavailable', 'No se pudo leer la fotografía.', 500 );
		}
		return $handle;
	}

	/**
	 * Remove only a verified contained file; missing files make cleanup idempotent.
	 *
	 * @param string $storage_key Relative key created by this class.
	 * @return true|WP_Error
	 */
	public function delete( $storage_key ) {
		$path = $this->key_path( $storage_key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		clearstatcache( true, $path );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}
		$existing = $this->existing_file( $storage_key );
		if ( is_wp_error( $existing ) || ! @unlink( $existing ) ) {
			return $this->error( 'file_delete_failed', 'No se pudo eliminar la fotografía.', 500 );
		}
		clearstatcache( true, $existing );
		if ( file_exists( $existing ) || is_link( $existing ) ) {
			return $this->error( 'file_delete_failed', 'No se pudo eliminar la fotografía.', 500 );
		}
		return true;
	}

	/**
	 * Stage deletion on the same filesystem so a failed SQL operation can roll back.
	 *
	 * @param string $storage_key Original photos key.
	 * @return array|WP_Error Opaque token for restore_delete()/finish_delete().
	 */
	public function stage_delete( $storage_key ) {
		if ( ! is_string( $storage_key ) || 0 !== strpos( $storage_key, 'photos/' ) ) {
			return $this->error( 'file_delete_failed', 'No se pudo eliminar la fotografía.', 500 );
		}
		$original = $this->existing_file( $storage_key );
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$directory = $this->directory( $root, 'trash' );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$random = $this->random_name();
		if ( is_wp_error( $random ) ) {
			return $random;
		}
		$staged_key = 'trash/' . $random . '.' . pathinfo( $storage_key, PATHINFO_EXTENSION );
		$staged     = $root . '/' . $staged_key;
		if ( file_exists( $staged ) || is_link( $staged ) || ! @rename( $original, $staged ) ) {
			return $this->error( 'file_delete_failed', 'No se pudo preparar la eliminación de la fotografía.', 500 );
		}
		if ( ! $this->verified_file( $root, $staged ) ) {
			@rename( $staged, $original );
			return $this->error( 'file_delete_failed', 'No se pudo preparar la eliminación de la fotografía.', 500 );
		}
		return array( 'original_key' => $storage_key, 'staged_key' => $staged_key );
	}

	/** @param array $token Staged deletion token. @return true|WP_Error */
	public function restore_delete( array $token ) {
		if ( ! $this->valid_token( $token ) ) {
			return $this->error( 'file_restore_failed', 'No se pudo restaurar la fotografía.', 500 );
		}
		$original = $this->key_path( $token['original_key'] );
		$staged   = $this->existing_file( $token['staged_key'] );
		if ( is_wp_error( $original ) || is_wp_error( $staged ) || file_exists( $original ) || is_link( $original )
			|| ! @rename( $staged, $original ) ) {
			return $this->error( 'file_restore_failed', 'No se pudo restaurar la fotografía. El administrador debe revisar el almacenamiento privado.', 500 );
		}
		$verified = $this->existing_file( $token['original_key'] );
		return is_wp_error( $verified ) ? $this->error( 'file_restore_failed', 'No se pudo restaurar la fotografía.', 500 ) : true;
	}

	/** @param array $token Staged deletion token. @return true|WP_Error */
	public function finish_delete( array $token ) {
		if ( ! $this->valid_token( $token ) ) {
			return $this->error( 'file_delete_failed', 'No se pudo eliminar la fotografía.', 500 );
		}
		return $this->delete( $token['staged_key'] );
	}

	/** @return string|WP_Error SHA-256, rejecting executable PHP polyglots. */
	private function inspect_bytes( $path, $expected_size ) {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return $this->error( 'upload_failed', 'No se pudo leer la fotografía recibida.', 500 );
		}
		$hash = hash_init( 'sha256' );
		$tail = '';
		$size = 0;
		$php_tag_pattern = filter_var( ini_get( 'short_open_tag' ), FILTER_VALIDATE_BOOLEAN ) ? '/<\?/' : '/<\?(?:php|=)/i';
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 65536 );
			if ( false === $chunk || ( '' === $chunk && ! feof( $handle ) ) ) {
				fclose( $handle );
				return $this->error( 'upload_failed', 'No se pudo leer la fotografía recibida.', 500 );
			}
			$size += strlen( $chunk );
			if ( $size > $expected_size || preg_match( $php_tag_pattern, $tail . $chunk ) ) {
				fclose( $handle );
				return $this->format_error();
			}
			hash_update( $hash, $chunk );
			$tail = substr( $chunk, -6 );
		}
		fclose( $handle );
		if ( $size !== $expected_size ) {
			return $this->format_error();
		}
		return hash_final( $hash );
	}

	/** @return string|WP_Error */
	private function key_path( $key ) {
		if ( ! is_string( $key ) || ! preg_match( '#\A(?:photos|trash|ai-temp)/[a-f0-9]{64}\.(?:jpg|png|webp)\z#', $key ) ) {
			return $this->error( 'file_unavailable', 'No se pudo acceder a la fotografía.', 500 );
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$directory = $this->directory( $root, dirname( $key ) );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		return $root . '/' . $key;
	}

	/** @return string|WP_Error */
	private function existing_file( $key ) {
		$path = $this->key_path( $key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		if ( ! $this->verified_file( $root, $path ) || ! is_readable( $path ) ) {
			return $this->error( 'file_unavailable', 'El archivo de la fotografía no está disponible.', 500 );
		}
		return $path;
	}

	/** Resolve a root that may not exist by resolving its nearest existing parent. */
	private function prospective_path( $configured ) {
		$ancestor = $configured;
		$missing  = array();
		while ( ! file_exists( $ancestor ) ) {
			if ( is_link( $ancestor ) ) {
				return $this->configuration_error();
			}
			$parent = dirname( $ancestor );
			if ( $parent === $ancestor ) {
				return $this->configuration_error();
			}
			array_unshift( $missing, basename( $ancestor ) );
			$ancestor = $parent;
		}
		$actual = realpath( $ancestor );
		if ( false === $actual || ! is_dir( $actual ) ) {
			return $this->configuration_error();
		}
		return $this->normalize_path( $actual ) . ( $missing ? '/' . implode( '/', $missing ) : '' );
	}

	/** Ensure a managed private directory cannot escape through a symlink. */
	private function directory( $root, $name ) {
		if ( ! in_array( $name, array( 'photos', 'trash', 'ai-temp' ), true ) ) {
			return $this->configuration_error();
		}
		$path = $root . '/' . $name;
		if ( is_link( $path ) || ( ! is_dir( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) ) {
			return $this->configuration_error();
		}
		$actual = realpath( $path );
		if ( false === $actual || ! $this->same_path( $actual, $path ) || ! $this->within( $actual, $root )
			|| ! $this->secure_directory( $actual ) ) {
			return $this->configuration_error();
		}
		return $this->normalize_path( $actual );
	}

	private function verified_file( $root, $path ) {
		clearstatcache( true, $path );
		$actual = realpath( $path );
		return false !== $actual && ! is_link( $path ) && is_file( $actual )
			&& $this->same_path( $actual, $path ) && $this->within( $actual, $root );
	}

	private function secure_directory( $path ) {
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			if ( ! @chmod( $path, 0700 ) ) {
				return false;
			}
			clearstatcache( true, $path );
			$permissions = @fileperms( $path );
			if ( false === $permissions || ( $permissions & 0777 ) !== 0700 ) {
				return false;
			}
		}
		return is_readable( $path ) && is_writable( $path );
	}

	private function secure_file( $path ) {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return is_readable( $path ) && is_writable( $path );
		}
		if ( ! @chmod( $path, 0600 ) ) {
			return false;
		}
		clearstatcache( true, $path );
		$permissions = @fileperms( $path );
		return false !== $permissions && ( $permissions & 0777 ) === 0600;
	}

	private function valid_token( array $token ) {
		return isset( $token['original_key'], $token['staged_key'] )
			&& is_string( $token['original_key'] ) && is_string( $token['staged_key'] )
			&& preg_match( '#\Aphotos/[a-f0-9]{64}\.(jpg|png|webp)\z#', $token['original_key'], $original )
			&& preg_match( '#\Atrash/[a-f0-9]{64}\.(jpg|png|webp)\z#', $token['staged_key'], $staged )
			&& $original[1] === $staged[1];
	}

	private function random_name() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Throwable $exception ) {
			return $this->error( 'storage_unavailable', 'El servidor no puede generar nombres seguros para las fotografías.', 503 );
		}
	}

	private function is_private( $root, array $public_roots ) {
		foreach ( $public_roots as $public ) {
			// An ancestor of the public directory is also too broad as a private root.
			if ( $this->same_path( $root, $public ) || $this->within( $root, $public ) || $this->within( $public, $root ) ) {
				return false;
			}
		}
		return true;
	}

	private function within( $child, $parent ) {
		$child  = $this->comparison_path( $child );
		$parent = $this->comparison_path( $parent );
		return 0 === strpos( $child, rtrim( $parent, '/' ) . '/' );
	}

	private function same_path( $left, $right ) {
		return $this->comparison_path( $left ) === $this->comparison_path( $right );
	}

	private function comparison_path( $path ) {
		$path = $this->normalize_path( $path );
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}

	private function normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		// Keep the double leading slash of a Windows UNC path.
		$unc  = '\\' === DIRECTORY_SEPARATOR && 0 === strpos( $path, '//' );
		$path = preg_replace( '#/+#', '/', $path );
		if ( $unc ) {
			$path = '/' . $path;
		}
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	private function is_absolute( $path ) {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 1 === preg_match( '#\A(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}[^\\\\/]+[\\\\/][^\\\\/]+)#', $path );
		}
		return 0 === strpos( $path, '/' );
	}

	private function ini_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		if ( ! preg_match( '/\A([0-9]+(?:\.[0-9]+)?)\s*([KMG]?)\z/i', $value, $parts ) ) {
			return -1;
		}
		$bytes = (float) $parts[1];
		$units = array( '' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824 );
		return (int) ( $bytes * $units[ strtoupper( $parts[2] ) ] );
	}

	private function format_error() {
		return $this->error( 'image_format', 'La fotografía debe ser un JPEG, PNG o WebP válido y no contener código ejecutable.', 415 );
	}

	private function configuration_error() {
		return $this->error( 'private_storage_unavailable', 'Configure una raíz privada, accesible y fuera del directorio público para las fotografías.', 503 );
	}

	private function error( $code, $message, $status ) {
		return new WP_Error( 'infocontainer_' . $code, $message, array( 'status' => $status ) );
	}
}
