<?php
/**
 * Validation of client input before it reaches the database.
 *
 * @package InfoContainer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Validator {
	/**
	 * Maximum number of Unicode characters accepted in observations.
	 */
	const OBSERVATIONS_MAX_LENGTH = 10000;

	/**
	 * Normalize and validate a cargo container identifier using ISO 6346.
	 *
	 * This validates the format and check digit, not ownership, existence or
	 * registration with Customs. Letters are never substituted for digits.
	 *
	 * @param mixed $value Identifier supplied by the client.
	 * @return string|WP_Error
	 */
	public static function identifier( $value ) {
		$normalized = self::normalize_code( $value );
		if ( is_wp_error( $normalized ) || ! preg_match( '/\A[A-Z]{3}U[0-9]{7}\z/', $normalized ) ) {
			return self::invalid( 'identifier', 'El identificador debe contener tres letras, U, seis dígitos de serie y un dígito de control.' );
		}

		// ISO 6346 assigns letters values 10 to 38, omitting multiples of 11.
		$letter_values = array(
			'A' => 10, 'B' => 12, 'C' => 13, 'D' => 14, 'E' => 15,
			'F' => 16, 'G' => 17, 'H' => 18, 'I' => 19, 'J' => 20,
			'K' => 21, 'L' => 23, 'M' => 24, 'N' => 25, 'O' => 26,
			'P' => 27, 'Q' => 28, 'R' => 29, 'S' => 30, 'T' => 31,
			'U' => 32, 'V' => 34, 'W' => 35, 'X' => 36, 'Y' => 37,
			'Z' => 38,
		);
		$sum = 0;
		for ( $position = 0; $position < 10; $position++ ) {
			$character = $normalized[ $position ];
			$value     = $position < 4 ? $letter_values[ $character ] : (int) $character;
			$sum      += $value * ( 1 << $position );
		}

		$check_digit = ( $sum % 11 ) % 10;
		if ( $check_digit !== (int) $normalized[10] ) {
			return self::invalid( 'identifier_check_digit', 'El dígito de control del identificador no es válido según ISO 6346.' );
		}

		return $normalized;
	}

	/**
	 * Normalize the separate ISO size/type code without inferring attributes.
	 *
	 * @param mixed $value Optional ISO code.
	 * @return string|WP_Error Empty string when unknown.
	 */
	public static function iso_code( $value ) {
		if ( null === $value ) {
			return '';
		}

		$normalized = self::normalize_code( $value );
		if ( is_wp_error( $normalized ) ) {
			return self::invalid( 'iso_code', 'El código ISO debe ser texto de cuatro caracteres alfanuméricos o estar vacío.' );
		}
		if ( '' === $normalized ) {
			return '';
		}
		if ( ! preg_match( '/\A[A-Z0-9]{4}\z/', $normalized ) ) {
			return self::invalid( 'iso_code', 'El código ISO debe contener cuatro caracteres alfanuméricos.' );
		}

		return $normalized;
	}

	/**
	 * Validate the inspection purpose: inventario, venta, recepción or entrega.
	 *
	 * @param mixed $value Client inspection type.
	 * @return string|WP_Error
	 */
	public static function inspection_type( $value ) {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'inventory', 'sale', 'receipt', 'delivery' ), true ) ) {
			return self::invalid( 'inspection_type', 'inspection_type debe ser inventory, sale, receipt o delivery.' );
		}

		return $value;
	}

	/**
	 * Convert an explicitly zoned ISO 8601 timestamp to a MySQL UTC datetime.
	 *
	 * Accepts YYYY-MM-DDTHH:MM:SS, optional fractional seconds, and Z or an
	 * offset +/-HH:MM. Fractions are discarded because storage uses seconds.
	 * Impossible dates, leap seconds, and values outside MySQL DATETIME's
	 * supported 1000-9999 year range are rejected, not silently normalized.
	 *
	 * @param mixed $value Optional date; null means current server time in UTC.
	 * @return string|WP_Error
	 */
	public static function inspected_at( $value = null ) {
		if ( null === $value ) {
			return current_time( 'mysql', true );
		}

		$pattern = '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]+))?(Z|[+-][0-9]{2}:[0-9]{2})\z/';
		if ( ! is_string( $value ) || ! preg_match( $pattern, $value, $parts ) ) {
			return self::invalid( 'inspected_at', 'inspected_at debe ser una fecha ISO 8601 con hora, segundos y zona Z o ±HH:MM.' );
		}

		$year   = (int) $parts[1];
		$month  = (int) $parts[2];
		$day    = (int) $parts[3];
		$hour   = (int) $parts[4];
		$minute = (int) $parts[5];
		$second = (int) $parts[6];
		$zone   = $parts[8];
		if ( $year < 1000 || ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return self::invalid( 'inspected_at', 'inspected_at contiene una fecha u hora imposible o fuera del rango admitido.' );
		}

		if ( 'Z' !== $zone && ( (int) substr( $zone, 1, 2 ) > 23 || (int) substr( $zone, 4, 2 ) > 59 ) ) {
			return self::invalid( 'inspected_at', 'La zona horaria de inspected_at no es válida.' );
		}

		// The regular expression and calendar checks forbid parser normalization.
		$timestamp = substr( $value, 0, 19 ) . ( 'Z' === $zone ? '+00:00' : $zone );
		$date      = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $timestamp );
		$errors    = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return self::invalid( 'inspected_at', 'No se pudo interpretar inspected_at como una fecha válida.' );
		}

		$date = $date->setTimezone( new DateTimeZone( 'UTC' ) );
		if ( (int) $date->format( 'Y' ) < 1000 || (int) $date->format( 'Y' ) > 9999 ) {
			return self::invalid( 'inspected_at', 'La fecha UTC de inspected_at está fuera del rango admitido.' );
		}

		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Accept plain text with line breaks and at most 10,000 Unicode characters.
	 *
	 * The limit applies to client input before WordPress strips markup.
	 *
	 * @param mixed $value Optional observations text.
	 * @return string|WP_Error
	 */
	public static function observations( $value = '' ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return self::invalid( 'observations', 'observations debe ser texto UTF-8 válido.' );
		}

		// Each valid UTF-8 character needs at most four bytes.
		if ( strlen( $value ) > self::OBSERVATIONS_MAX_LENGTH * 4 ) {
			return self::invalid( 'observations_length', 'observations admite un máximo de 10.000 caracteres.' );
		}
		$length = preg_match_all( '/./us', $value, $characters );
		if ( false === $length || $length > self::OBSERVATIONS_MAX_LENGTH ) {
			return self::invalid( 'observations_length', 'observations admite un máximo de 10.000 caracteres.' );
		}

		return sanitize_textarea_field( $value );
	}

	/**
	 * Reject unexpected fields, including all server-calculated properties.
	 *
	 * @param array $data    Client payload.
	 * @param array $allowed Names accepted by this endpoint.
	 * @return true|WP_Error
	 */
	public static function assert_allowed_fields( array $data, array $allowed ) {
		foreach ( array_keys( $data ) as $field ) {
			if ( ! in_array( $field, $allowed, true ) ) {
				return self::invalid( 'unexpected_field', 'La solicitud contiene campos no permitidos o calculados por el servidor.' );
			}
		}

		return true;
	}

	/**
	 * Remove whitespace and hyphens while preserving digits and their zeros.
	 *
	 * @param mixed $value Client code.
	 * @return string|WP_Error
	 */
	private static function normalize_code( $value ) {
		if ( ! is_string( $value ) ) {
			return self::invalid( 'code', 'El código debe ser texto.' );
		}

		$normalized = preg_replace( '/[\s\p{Z}\p{Pd}]+/u', '', $value );
		if ( null === $normalized ) {
			return self::invalid( 'code', 'El código debe ser texto UTF-8 válido.' );
		}

		return strtoupper( $normalized );
	}

	/**
	 * Create a client error without internal implementation details.
	 *
	 * @param string $suffix  Stable error suffix.
	 * @param string $message Public message.
	 * @return WP_Error
	 */
	private static function invalid( $suffix, $message ) {
		return new WP_Error( 'infocontainer_invalid_' . $suffix, $message, array( 'status' => 400 ) );
	}
}
