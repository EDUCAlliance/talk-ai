<?php

declare(strict_types=1);

return [
	'routes' => [
		// Page routes
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Bot API routes
		['name' => 'bot#index', 'url' => '/api/v1/bots', 'verb' => 'GET'],
		['name' => 'bot#show', 'url' => '/api/v1/bots/{id}', 'verb' => 'GET'],
		['name' => 'bot#create', 'url' => '/api/v1/bots', 'verb' => 'POST'],
		['name' => 'bot#update', 'url' => '/api/v1/bots/{id}', 'verb' => 'PUT'],
		['name' => 'bot#destroy', 'url' => '/api/v1/bots/{id}', 'verb' => 'DELETE'],
		['name' => 'bot#tools', 'url' => '/api/v1/bots/{id}/tools', 'verb' => 'GET'],
		['name' => 'bot#listPublic', 'url' => '/api/v1/public-bots', 'verb' => 'GET'],
		['name' => 'bot#showPublic', 'url' => '/api/v1/public-bots/{id}', 'verb' => 'GET'],

		// Bot approval workflow routes
		['name' => 'bot#submit', 'url' => '/api/v1/bots/{id}/submit', 'verb' => 'POST'],
		['name' => 'bot#approve', 'url' => '/api/v1/bots/{id}/approve', 'verb' => 'POST'],
		['name' => 'bot#reject', 'url' => '/api/v1/bots/{id}/reject', 'verb' => 'POST'],
		['name' => 'bot#enableTest', 'url' => '/api/v1/bots/{id}/enable-test', 'verb' => 'POST'],
		['name' => 'bot#pendingApprovals', 'url' => '/api/v1/approvals', 'verb' => 'GET'],
		['name' => 'bot#permissions', 'url' => '/api/v1/permissions', 'verb' => 'GET'],
		['name' => 'bot#adminIndex', 'url' => '/api/v1/admin/bots', 'verb' => 'GET'],
		['name' => 'bot#adminUpdate', 'url' => '/api/v1/admin/bots/{id}', 'verb' => 'PUT'],

		// Personal trace activity routes
		['name' => 'trace#index', 'url' => '/api/v1/traces', 'verb' => 'GET'],
		['name' => 'trace#show', 'url' => '/api/v1/traces/{id}', 'verb' => 'GET'],
		['name' => 'trace#destroy', 'url' => '/api/v1/traces/{id}', 'verb' => 'DELETE'],
		['name' => 'trace#clearMine', 'url' => '/api/v1/traces', 'verb' => 'DELETE'],

		// Talk bot management routes (for Smart Picker)
		['name' => 'talk_bot#status', 'url' => '/api/v1/talk/bot-status/{roomToken}', 'verb' => 'GET'],
		['name' => 'talk_bot#enableBot', 'url' => '/api/v1/talk/enable-bot/{roomToken}', 'verb' => 'POST'],
		['name' => 'talk_bot#rooms', 'url' => '/api/v1/talk/rooms', 'verb' => 'GET'],
		['name' => 'talk_bot#startBotChat', 'url' => '/api/v1/talk/start-bot-chat', 'verb' => 'POST'],

		['name' => 'rag#index', 'url' => '/api/v1/bots/{botId}/sources', 'verb' => 'GET'],
		['name' => 'rag#store', 'url' => '/api/v1/bots/{botId}/sources', 'verb' => 'POST'],
		['name' => 'rag#destroy', 'url' => '/api/v1/bots/{botId}/sources/{sourceId}', 'verb' => 'DELETE'],
		['name' => 'rag#reindex', 'url' => '/api/v1/bots/{botId}/sources/{sourceId}/reindex', 'verb' => 'POST'],

		// Settings API routes
		['name' => 'settings#index', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
		['name' => 'settings#models', 'url' => '/api/v1/models', 'verb' => 'GET'],
		['name' => 'settings#groups', 'url' => '/api/v1/groups', 'verb' => 'GET'],
		['name' => 'settings#teams', 'url' => '/api/v1/teams', 'verb' => 'GET'],
		['name' => 'app_icon#show', 'url' => '/api/v1/app-icon/{variant}', 'verb' => 'GET'],
		['name' => 'app_icon#preview', 'url' => '/api/v1/admin/app-icon-preview/{variant}', 'verb' => 'GET'],
		['name' => 'app_icon#upload', 'url' => '/api/v1/admin/app-icon-upload/{variant}', 'verb' => 'POST'],

		['name' => 'tools#available', 'url' => '/api/v1/tools', 'verb' => 'GET'],
		['name' => 'tools#wikiLocations', 'url' => '/api/v1/wiki/locations', 'verb' => 'GET'],
		['name' => 'tools#index', 'url' => '/api/v1/admin/tools', 'verb' => 'GET'],
		['name' => 'tools#show', 'url' => '/api/v1/admin/tools/{id}', 'verb' => 'GET'],
		['name' => 'tools#create', 'url' => '/api/v1/admin/tools', 'verb' => 'POST'],
		['name' => 'tools#update', 'url' => '/api/v1/admin/tools/{id}', 'verb' => 'PUT'],
		['name' => 'tools#destroy', 'url' => '/api/v1/admin/tools/{id}', 'verb' => 'DELETE'],
		['name' => 'tools#test', 'url' => '/api/v1/admin/tools/test', 'verb' => 'POST'],

		['name' => 'settings#reindexAllEmbeddings', 'url' => '/api/v1/admin/embeddings/reindex-all', 'verb' => 'POST'],

		// Provider health-check routes
		['name' => 'tools#testDocling', 'url' => '/api/v1/admin/docling/test', 'verb' => 'POST'],
		['name' => 'tools#testVision', 'url' => '/api/v1/admin/vision/test', 'verb' => 'POST'],
		['name' => 'tools#testSpeech', 'url' => '/api/v1/admin/speech/test', 'verb' => 'POST'],

		// Rate limit routes
		['name' => 'settings#rateLimitStatus', 'url' => '/api/v1/admin/ratelimit/status', 'verb' => 'GET'],
		['name' => 'settings#processQueue', 'url' => '/api/v1/admin/ratelimit/process', 'verb' => 'POST'],

		// Webhook route
		['name' => 'webhook#talk', 'url' => '/webhook/talk', 'verb' => 'POST'],

	]
];
