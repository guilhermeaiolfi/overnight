<?php

declare(strict_types=1);

namespace Tests\ON\Session;

use ON\Session\NativeSession;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NativeSessionTest extends TestCase
{
	private function createSession(array $options = []): NativeSession
	{
		return new NativeSession(array_merge([
			'use_cookies' => 0,
			'cache_limiter' => '',
		], $options));
	}

	protected function setUp(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}

		$_SESSION = [];
	}

	public function testConstructorDoesNotStartSession(): void
	{
		$session = $this->createSession();

		$this->assertSame(PHP_SESSION_NONE, session_status());
	}

	public function testGetReturnsDefaultWhenSessionNotStarted(): void
	{
		$session = $this->createSession();

		$this->assertSame('default', $session->get('key', 'default'));
	}

	public function testSetStartsSession(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');

		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		$this->assertSame('value', $_SESSION['key']);
	}

	public function testHasReturnsFalseWhenSessionNotStarted(): void
	{
		$session = $this->createSession();

		// has() triggers ensureStarted(), but if the session was never active
		// and no cookie exists, session_start() will create a new empty one.
		// We verify that has() works correctly for missing keys.
		$this->assertFalse($session->has('key'));
	}

	public function testHasReturnsTrueForExplicitlySetNullValue(): void
	{
		$session = $this->createSession();
		$session->set('key', null);

		$this->assertTrue($session->has('key'));
	}

	public function testAllReturnsEmptyArrayWhenSessionNotStarted(): void
	{
		$session = $this->createSession();

		// all() triggers ensureStarted(), creating an empty session.
		$this->assertSame([], $session->all());
	}

	public function testAllReturnsSessionData(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');

		$this->assertSame(['key' => 'value'], $session->all());
	}

	public function testRemoveDeletesKey(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');
		$session->remove('key');

		$this->assertFalse($session->has('key'));
	}

	public function testDestroyClearsSessionData(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');
		$session->destroy();

		$this->assertEmpty($_SESSION);
		$this->assertSame(PHP_SESSION_NONE, session_status());
	}

	public function testGetIdReturnsNullWhenNoSession(): void
	{
		$session = $this->createSession();

		$this->assertNull($session->getId());
	}

	public function testGetIdReturnsStringAfterSessionStart(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');

		$this->assertNotNull($session->getId());
		$this->assertNotSame('', $session->getId());
	}

	public function testRegenerateIdReturnsBool(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');

		$this->assertTrue($session->regenerateId());
	}

	public function testRegenerateIdWithDeleteOldSession(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');

		$this->assertTrue($session->regenerateId(true));
	}

	public function testCloseReturnsBoolAndEndsSession(): void
	{
		$session = $this->createSession();
		$session->set('key', 'value');

		$this->assertTrue($session->close());
		$this->assertSame(PHP_SESSION_NONE, session_status());
	}
}
