<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Webhook\TalkActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TalkActorTest extends TestCase {
	#[DataProvider('actors')]
	public function testOnlyPeopleWithoutAnAccountAreGuests(string $actorId, bool $guest): void {
		$this->assertSame($guest, TalkActor::isGuest($actorId));
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function actors(): array {
		return [
			'guest of a public conversation' => ['guests/3f2a9c', true],
			'invited by e-mail' => ['emails/7b1d', true],
			'writes through a bridge' => ['bridged/Ada', true],
			'signed-in user' => ['users/alice', false],
			'user of another server' => ['federated_users/alice@cloud.example', false],
			'user id as the API passes it' => ['alice', false],
			'user named like the guest type' => ['guests', false],
			'user id that contains the word' => ['users/guests', false],
			'empty' => ['', false],
		];
	}
}
