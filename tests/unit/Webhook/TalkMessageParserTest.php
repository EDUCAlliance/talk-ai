<?php

declare(strict_types=1);

namespace OCA\EducAI\Tests\Unit\Webhook;

use OCA\EducAI\Webhook\IncomingTalkAttachment;
use OCA\EducAI\Webhook\TalkAttachmentNormalizer;
use OCA\EducAI\Webhook\TalkMessageParser;
use PHPUnit\Framework\TestCase;

class TalkMessageParserTest extends TestCase {
	public function testParseUsesStructuredContentParametersEvenWhenObjectNameIsEmpty(): void {
		$parser = new TalkMessageParser(new TalkAttachmentNormalizer());
		$payload = [
			'actor' => ['id' => 'alice'],
			'target' => ['id' => 'room-a'],
			'object' => [
				'id' => 99,
				'name' => '',
				'content' => json_encode([
					'message' => '@helper Bitte prüfe die Uploads',
					'parent' => ['id' => 42],
					'parameters' => [
						'photo' => [
							'type' => 'media',
							'name' => 'screenshot.png',
							'mimetype' => 'image/png',
							'id' => '123',
						],
						'voice' => [
							'type' => 'voice',
							'name' => 'note.m4a',
							'mimetype' => 'audio/mp4',
							'id' => '124',
						],
						'doc' => [
							'type' => 'file',
							'name' => 'handout.pdf',
							'mimetype' => 'application/pdf',
							'id' => '125',
						],
					],
				], JSON_THROW_ON_ERROR),
			],
		];

		$message = $parser->parse($payload);

		$this->assertSame('@helper Bitte prüfe die Uploads', $message->getText());
		$this->assertSame('alice', $message->getActorId());
		$this->assertSame('room-a', $message->getRoomToken());
		$this->assertSame(99, $message->getMessageId());
		$this->assertSame(42, $message->getInReplyTo());
		$this->assertCount(3, $message->getAttachments());
		$this->assertSame(IncomingTalkAttachment::KIND_IMAGE, $message->getAttachments()[0]->getKind());
		$this->assertSame(IncomingTalkAttachment::KIND_AUDIO, $message->getAttachments()[1]->getKind());
		$this->assertSame(IncomingTalkAttachment::KIND_DOCUMENT, $message->getAttachments()[2]->getKind());
		$this->assertSame('alice', $message->getAttachments()[0]->getFileRef()['_educai_owner_uid']);
	}

	public function testAttachmentNormalizerRejectsVideoAsUnsupported(): void {
		$normalizer = new TalkAttachmentNormalizer();
		$attachments = $normalizer->normalize([
			'video' => [
				'type' => 'media',
				'name' => 'clip.mp4',
				'mimetype' => 'video/mp4',
				'id' => '126',
			],
		]);

		$this->assertCount(1, $attachments);
		$this->assertSame(IncomingTalkAttachment::KIND_UNSUPPORTED, $attachments[0]->getKind());
		$this->assertSame('Video attachments are not supported yet.', $attachments[0]->getUnsupportedReason());
	}

	public function testParseUsesTalkBotInReplyToObjectIdAsParent(): void {
		$parser = new TalkMessageParser(new TalkAttachmentNormalizer());
		$payload = [
			'actor' => ['id' => 'alice'],
			'target' => ['id' => 'room-a'],
			'object' => [
				'id' => 100,
				'name' => 'message',
				'content' => json_encode([
					'message' => '@helper weiter bitte',
					'parameters' => [],
				], JSON_THROW_ON_ERROR),
				'inReplyTo' => [
					'object' => [
						'id' => 42,
						'content' => json_encode([
							'message' => 'Thread root',
							'parameters' => [],
						], JSON_THROW_ON_ERROR),
					],
				],
			],
		];

		$message = $parser->parse($payload);

		$this->assertSame(100, $message->getMessageId());
		$this->assertSame(42, $message->getInReplyTo());
	}

	public function testParseUsesTalkThreadMetadataAsThreadRoot(): void {
		$parser = new TalkMessageParser(new TalkAttachmentNormalizer());
		$payload = [
			'actor' => ['id' => 'alice'],
			'target' => ['id' => 'room-a'],
			'object' => [
				'id' => 1419,
				'name' => 'message',
				'meta_data' => json_encode([
					'thread_id' => 1398,
				], JSON_THROW_ON_ERROR),
				'content' => json_encode([
					'message' => 'Weiter im Thread',
					'parameters' => [],
				], JSON_THROW_ON_ERROR),
			],
		];

		$message = $parser->parse($payload);

		$this->assertSame(1419, $message->getMessageId());
		$this->assertSame(1398, $message->getThreadRootMessageId());
	}
}
