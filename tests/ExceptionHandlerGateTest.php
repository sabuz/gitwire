<?php
/**
 * Tests for when the exception handler takes over.
 *
 * @package Gitwire
 */

use Gitwire\Error_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Covers the guard that decides whether Gitwire owns exception handling.
 *
 * Taking the handler over site-wide misattributes every uncaught exception on
 * the site to Gitwire, so it has to be scoped to a live install.
 *
 * @covers Gitwire\Error_Handler
 */
class ExceptionHandlerGateTest extends TestCase {

	/**
	 * Handler in place before the test ran.
	 *
	 * @var callable|null
	 */
	private $original;

	protected function setUp(): void {
		gitwire_test_reset_options();
		Error_Handler::disarm_exception_handler();
		$this->original = set_exception_handler( null );
	}

	protected function tearDown(): void {
		Error_Handler::disarm_exception_handler();
		set_exception_handler( $this->original );
	}

	/**
	 * Reads the installed handler without permanently changing it.
	 *
	 * @return callable|null
	 */
	private function current_handler() {
		$handler = set_exception_handler( null );
		set_exception_handler( $handler );
		return $handler;
	}

	/**
	 * Marks the request as one that manages Gitwire state.
	 *
	 * @return void
	 */
	private function as_admin(): void {
		$GLOBALS['gitwire_test_is_admin'] = true;
	}

	public function test_a_front_end_request_never_arms_even_with_a_guard_present(): void {
		gitwire_test_set_option( Error_Handler::GUARD_OPTION, [ 'context' => 'update' ] );

		Error_Handler::maybe_arm_exception_handler();

		$this->assertNull( $this->current_handler(), 'the front end must not own exception handling' );
	}

	public function test_an_admin_request_without_a_guard_does_not_arm(): void {
		$this->as_admin();

		Error_Handler::maybe_arm_exception_handler();

		$this->assertNull( $this->current_handler(), 'no install in flight means nothing to catch' );
	}

	public function test_an_admin_request_with_a_guard_arms(): void {
		$this->as_admin();
		gitwire_test_set_option( Error_Handler::GUARD_OPTION, [ 'context' => 'activation' ] );

		Error_Handler::maybe_arm_exception_handler();

		$this->assertSame(
			[ Error_Handler::class, 'handle_uncaught_exception' ],
			$this->current_handler()
		);
	}

	public function test_arming_directly_installs_the_handler(): void {
		Error_Handler::arm_exception_handler();

		$this->assertSame(
			[ Error_Handler::class, 'handle_uncaught_exception' ],
			$this->current_handler()
		);
	}

	public function test_arming_twice_does_not_stack_handlers(): void {
		$sentinel = static function ( $e ) {};
		set_exception_handler( $sentinel );

		Error_Handler::arm_exception_handler();
		Error_Handler::arm_exception_handler();
		Error_Handler::disarm_exception_handler();

		// One disarm must be enough to get back to the caller's handler.
		$this->assertSame( $sentinel, $this->current_handler() );
	}

	public function test_disarming_restores_the_previous_handler(): void {
		$sentinel = static function ( $e ) {};
		set_exception_handler( $sentinel );

		Error_Handler::arm_exception_handler();
		$this->assertNotSame( $sentinel, $this->current_handler() );

		Error_Handler::disarm_exception_handler();
		$this->assertSame( $sentinel, $this->current_handler() );
	}

	public function test_disarming_without_arming_leaves_the_handler_alone(): void {
		$sentinel = static function ( $e ) {};
		set_exception_handler( $sentinel );

		Error_Handler::disarm_exception_handler();

		$this->assertSame( $sentinel, $this->current_handler() );
	}

	public function test_register_hooks_arming_to_the_guard_option_lifecycle(): void {
		Error_Handler::register();

		$actions = gitwire_test_actions();

		$this->assertArrayHasKey( 'add_option_' . Error_Handler::GUARD_OPTION, $actions );
		$this->assertArrayHasKey( 'update_option_' . Error_Handler::GUARD_OPTION, $actions );
		$this->assertArrayHasKey( 'delete_option_' . Error_Handler::GUARD_OPTION, $actions );
	}

	public function test_the_guard_option_name_matches_what_the_installer_writes(): void {
		// The hook names above are built from this, so a rename must not go unnoticed.
		$this->assertSame( 'gitwire_running_task', Error_Handler::GUARD_OPTION );
	}

	public function test_a_guard_without_a_timestamp_is_still_honoured(): void {
		// Written before started_at existed; one pass rather than a veto.
		$this->assertTrue( $this->guard_is_current( [ 'context' => 'update' ] ) );
	}

	public function test_a_fresh_guard_is_actionable(): void {
		$this->assertTrue( $this->guard_is_current( [ 'started_at' => time() ] ) );
	}

	public function test_an_expired_guard_is_ignored(): void {
		$stale = time() - Error_Handler::GUARD_MAX_AGE - 1;

		// Otherwise an unrelated fatal rolls back an install that already finished.
		$this->assertFalse( $this->guard_is_current( [ 'started_at' => $stale ] ) );
	}

	public function test_a_non_array_guard_is_ignored(): void {
		$this->assertFalse( $this->guard_is_current( 'corrupted' ) );
	}

	/**
	 * Reaches the private age check the shutdown handler gates on.
	 *
	 * @param mixed $pending Guard record.
	 * @return bool
	 */
	private function guard_is_current( $pending ): bool {
		$method = new ReflectionMethod( Error_Handler::class, 'guard_is_current' );
		return (bool) $method->invoke( null, $pending );
	}
}
