<?php

declare(strict_types=1);

namespace OCA\EducAI\Webhook;

/**
 * Who wrote a Talk message. Talk names the sender as "<actor type>/<actor id>",
 * for example "users/alice" or "guests/3f2a…".
 */
final class TalkActor {
	/**
	 * Actor types of people who take part without being signed in: guests of a public
	 * conversation, people invited by e-mail and people who write through a bridge.
	 */
	private const GUEST_TYPES = ['guests', 'emails', 'bridged'];

	/**
	 * Say whether the sender is a guest. A guest has no account, so groups, teams and
	 * ownership say nothing about them.
	 */
	public static function isGuest(string $actorId): bool {
		$separator = strpos($actorId, '/');

		return $separator !== false && in_array(substr($actorId, 0, $separator), self::GUEST_TYPES, true);
	}
}
