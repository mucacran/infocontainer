<?php
/**
 * Isolated permission regression checks; run with `php -n tests/permissions.php`.
 *
 * This runner uses in-memory WordPress doubles. It does NOT bootstrap WordPress,
 * use a database, assign users, send HTTP requests, or exercise any AI route.
 * It verifies the plugin's real registration and permission methods, not native
 * WordPress authentication, persistence, activation, or a hosted installation.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', __DIR__ . '/_no_wordpress_bootstrap_/' );

/** Minimal WP_Error surface used by the code under test. */
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

/** Mirrors get_role/add_role/add_cap behavior relevant here, in memory only. */
class Permission_Test_Role {
	public $name;
	public $capabilities;
	public $display_name;
	public function __construct( $name, $display_name, array $capabilities ) {
		$this->name = $name;
		$this->display_name = $display_name;
		$this->capabilities = $capabilities;
	}
	public function add_cap( $capability, $grant = true ) {
		$this->capabilities[ $capability ] = $grant;
		$GLOBALS['permission_test_writes'][] = array( 'add_cap', $this->name, $capability, $grant );
	}
}

function get_role( $name ) {
	return isset( $GLOBALS['permission_test_roles'][ $name ] ) ? $GLOBALS['permission_test_roles'][ $name ] : null;
}

function add_role( $name, $display_name, $capabilities = array() ) {
	// Native WordPress returns null and does not update an existing role.
	if ( empty( $name ) || get_role( $name ) ) {
		return null;
	}
	$role = new Permission_Test_Role( $name, $display_name, $capabilities );
	$GLOBALS['permission_test_roles'][ $name ] = $role;
	$GLOBALS['permission_test_writes'][] = array( 'add_role', $name, $capabilities );
	return $role;
}

function get_option( $name, $default = false ) {
	if ( 'infocontainer_db_version' !== $name ) {
		throw new RuntimeException( 'Unexpected option access: ' . $name );
	}
	++$GLOBALS['permission_test_version_reads'];
	return '1.2.0';
}

/** Any accidental schema/database operation fails the test immediately. */
class Permission_Test_No_Database {
	public $prefix = 'isolated_';
	public function __call( $name, $arguments ) {
		throw new RuntimeException( 'Database access is forbidden in this runner: ' . $name );
	}
}

function get_current_user_id() { return $GLOBALS['permission_test_user_id']; }
function current_user_can( $capability ) {
	foreach ( $GLOBALS['permission_test_user_roles'] as $name ) {
		$role = get_role( $name );
		if ( $role && ! empty( $role->capabilities[ $capability ] ) ) {
			return true;
		}
	}
	return false;
}

require_once dirname( __DIR__ ) . '/includes/class-infocontainer-install.php';
require_once dirname( __DIR__ ) . '/includes/class-infocontainer-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-infocontainer-rest-api.php';

function permission_test_reset_error() {
	// The plugin keeps a request-local error. Each fixture represents a new request.
	$property = new ReflectionProperty( 'InfoContainer_Install', 'error' );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	$property->setValue( null, null );
}

function permission_test_fixture( $operator_capabilities = null ) {
	permission_test_reset_error();
	$GLOBALS['permission_test_roles'] = array(
		'administrator' => new Permission_Test_Role( 'administrator', 'Administrator', array( 'read' => true, 'manage_options' => true, 'edit_users' => true, 'manage_infocontainer' => true ) ),
		'subscriber' => new Permission_Test_Role( 'subscriber', 'Subscriber', array( 'read' => true ) ),
		'editor' => new Permission_Test_Role( 'editor', 'Editor', array( 'read' => true, 'edit_posts' => true ) ),
	);
	if ( null !== $operator_capabilities ) {
		$GLOBALS['permission_test_roles'][ InfoContainer_Install::OPERATOR_ROLE ] = new Permission_Test_Role( InfoContainer_Install::OPERATOR_ROLE, 'Operador de InfoContainer', $operator_capabilities );
	}
	$GLOBALS['permission_test_writes'] = array();
	$GLOBALS['permission_test_version_reads'] = 0;
	$GLOBALS['permission_test_user_id'] = 0;
	$GLOBALS['permission_test_user_roles'] = array();
	$GLOBALS['wpdb'] = new Permission_Test_No_Database();
}

function permission_test_snapshot() {
	$result = array();
	foreach ( $GLOBALS['permission_test_roles'] as $name => $role ) {
		$result[ $name ] = array( $role->display_name, $role->capabilities );
	}
	return $result;
}

$permission_test_assertions = 0;
function permission_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$GLOBALS['permission_test_assertions'];
}

function permission_test_error( $result, $code, $status, $message ) {
	permission_test_assert( is_wp_error( $result ) && $result->get_error_code() === $code && $result->get_error_data()['status'] === $status, $message );
}

try {
	$expected = array( 'read' => true, 'manage_infocontainer' => true );
	permission_test_assert( '1.2.0' === InfoContainer_Install::DB_VERSION, 'The data schema version must remain 1.2.0.' );
	permission_test_fixture();
	$before = permission_test_snapshot();
	permission_test_assert( true === InfoContainer_Install::maybe_upgrade(), 'An active installation with current schema must register the role.' );
	$operator = get_role( InfoContainer_Install::OPERATOR_ROLE );
	permission_test_assert( $operator && $expected === $operator->capabilities, 'New operator must have exactly the two required grants.' );
	permission_test_assert( 1 === count( $GLOBALS['permission_test_writes'] ) && 'add_role' === $GLOBALS['permission_test_writes'][0][0], 'Creating the operator must perform only one role write.' );
	permission_test_assert( 1 === $GLOBALS['permission_test_version_reads'], 'Active installation must check the current schema without database migration.' );
	$after = permission_test_snapshot();
	unset( $after[ InfoContainer_Install::OPERATOR_ROLE ] );
	permission_test_assert( $before === $after, 'Administrator and all unrelated roles must remain intact.' );
	$GLOBALS['permission_test_writes'] = array();
	$before = permission_test_snapshot();
	permission_test_assert( true === InfoContainer_Install::maybe_upgrade(), 'Repeated role registration must succeed.' );
	permission_test_assert( array() === $GLOBALS['permission_test_writes'] && $before === permission_test_snapshot(), 'Repeated registration must not write or change roles.' );

	foreach ( array( array( 'read' => true ), array( 'manage_infocontainer' => true ), array( 'read' => false, 'manage_infocontainer' => true ), array( 'read' => true, 'manage_infocontainer' => false ) ) as $caps ) {
		permission_test_fixture( $caps );
		permission_test_assert( true === InfoContainer_Install::maybe_upgrade(), 'A compatible role with one missing/false grant must be repaired.' );
		$actual = get_role( InfoContainer_Install::OPERATOR_ROLE )->capabilities;
		ksort( $actual );
		$sorted_expected = $expected;
		ksort( $sorted_expected );
		permission_test_assert( $sorted_expected === $actual, 'Repair must leave exactly the two expected grants.' );
		permission_test_assert( 1 === count( $GLOBALS['permission_test_writes'] ) && 'add_cap' === $GLOBALS['permission_test_writes'][0][0], 'Repair must write only the missing/false capability.' );
	}

	permission_test_fixture( $expected );
	unset( get_role( 'administrator' )->capabilities['manage_infocontainer'] );
	$before = permission_test_snapshot();
	permission_test_assert( true === InfoContainer_Install::maybe_upgrade(), 'The existing administrator grant must still be maintained.' );
	$before['administrator'][1]['manage_infocontainer'] = true;
	permission_test_assert( $before === permission_test_snapshot(), 'Maintaining the administrator grant must preserve every other grant and role.' );
	permission_test_assert( 1 === count( $GLOBALS['permission_test_writes'] ) && array( 'add_cap', 'administrator', 'manage_infocontainer', true ) === $GLOBALS['permission_test_writes'][0], 'Only the original administrator capability may be repaired.' );

	foreach ( array( true, false ) as $extra_grant ) {
		permission_test_fixture( array( 'read' => true, 'manage_infocontainer' => true, 'manage_options' => $extra_grant ) );
		$before = permission_test_snapshot();
		$result = InfoContainer_Install::maybe_upgrade();
		permission_test_error( $result, 'infocontainer_operator_role_conflict', 503, 'An incompatible pre-existing role must report a conflict.' );
		permission_test_assert( $before === permission_test_snapshot() && array() === $GLOBALS['permission_test_writes'], 'A collision must not mutate the colliding role or any other role.' );
		permission_test_assert( $result === InfoContainer_Install::get_error(), 'A role collision must be visible to REST permission checks.' );
	}

	permission_test_fixture( $expected );
	$api = new InfoContainer_REST_API( new InfoContainer_Repository( $GLOBALS['wpdb'] ) );
	$GLOBALS['permission_test_user_roles'] = array( InfoContainer_Install::OPERATOR_ROLE );
	permission_test_error( $api->check_permissions(), 'infocontainer_authentication_required', 401, 'An anonymous identity must be rejected even if the capability double grants access.' );
	$GLOBALS['permission_test_user_id'] = 41;
	$GLOBALS['permission_test_user_roles'] = array( 'subscriber' );
	permission_test_error( $api->check_permissions(), 'infocontainer_forbidden', 403, 'Authenticated identity without manage_infocontainer must be rejected.' );
	$GLOBALS['permission_test_user_roles'] = array( InfoContainer_Install::OPERATOR_ROLE );
	permission_test_assert( true === $api->check_permissions(), 'Authenticated operator must pass the actual plugin permission callback.' );
	$GLOBALS['permission_test_user_roles'] = array( 'administrator' );
	permission_test_assert( true === $api->check_permissions(), 'Administrator access must remain available.' );
	permission_test_assert( array() === $GLOBALS['permission_test_writes'], 'Permission callbacks must not write roles.' );

	echo 'PASS: ' . $permission_test_assertions . " isolated assertions; no WordPress bootstrap, database, HTTP, user assignments, or AI calls.\n";
	echo "Not covered: real activation/schema installation, native authentication, role persistence, or hosted behavior.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
