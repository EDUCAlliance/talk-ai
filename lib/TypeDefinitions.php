<?php

declare(strict_types=1);

namespace OCA\EducAI;

/**
 * Shared array-shape contracts for service, entity, and test docblocks.
 *
 * @psalm-type OnboardingAnswer=array{id:string,text?:string,next:?string,type?:string}
 * @psalm-type OnboardingQuestion=array{id:string,text:string,answers:array<int,OnboardingAnswer>}
 * @psalm-type OnboardingQuestionTree=array{start:string,questions:array<int,OnboardingQuestion>}
 * @psalm-type McpToolLoadoutEntry=array{tool:\OCA\EducAI\Db\Tool,config:array<string,mixed>}
 * @psalm-type BuiltInToolLoadoutEntry=array{name:string,config:array<string,mixed>}
 * @psalm-type SplitToolLoadout=array{mcp:array<int,McpToolLoadoutEntry>,built_in:array<int,BuiltInToolLoadoutEntry>}
 * @psalm-type BotToolAssignmentView=array{tool?:\OCA\EducAI\Db\Tool,tool_id:?int,builtin_name:?string,is_builtin:bool,config:array<string,mixed>}
 * @psalm-type ToolAssignmentPayload=array{tool_id:?int,builtin_name:?string,is_builtin:bool,config:array<string,mixed>}
 * @psalm-type MessageContext=array{attachments:array<int,\OCA\EducAI\Webhook\IncomingTalkAttachment>,document_source_ids:array<int,int>,image_source_ids:array<int,int>,attachment_only:bool}
 * @psalm-type MessageContextInput=array{attachments?:array<int,\OCA\EducAI\Webhook\IncomingTalkAttachment>,document_source_ids?:array<int,int|string>,image_source_ids?:array<int,int|string>,attachment_only?:bool}
 * @psalm-type AttachmentSummary=array{has_images:bool,has_audio:bool,has_room_documents:bool,has_room_images:bool,image_names:array<int,string>,audio_names:array<int,string>,document_names:array<int,string>}
 * @psalm-type InvocationContext=array{bot_id:?int,room_token:?string,attachments:array<int,\OCA\EducAI\Webhook\IncomingTalkAttachment>,document_source_ids:array<int,int>,image_source_ids:array<int,int>}
 * @psalm-type InvocationContextInput=array{bot_id?:?int,room_token?:?string,attachments?:array<int,\OCA\EducAI\Webhook\IncomingTalkAttachment>,document_source_ids?:array<int,int|string>,image_source_ids?:array<int,int|string>}
 * @psalm-type ToolExecutionPolicy=array{kind:string,read_only:bool,idempotent:bool,destructive:bool,loop_threshold:int,source:string}
 * @psalm-type ToolDefinition=array{name:string,description:string,schema:array<string,mixed>,policy?:ToolExecutionPolicy}
 * @psalm-type ToolMapEntry=array{tool:\OCA\EducAI\Db\Tool|null,config:array<string,mixed>,definition:array<string,mixed>,invokeName:string,policy?:ToolExecutionPolicy}
 * @psalm-type BuiltInToolMapEntry=array{name:string,definition:array<string,mixed>,config:array<string,mixed>,policy?:ToolExecutionPolicy}
 * @psalm-type ToolDefinitionBuildResult=array{definitions:array<int,array<string,mixed>>,map:array<string,ToolMapEntry>,builtIn:array<string,BuiltInToolMapEntry>}
 * @psalm-type LlmToolCall=array{id:string,type:string,function:array{name:string,arguments:string}}
 */
final class TypeDefinitions {
	private function __construct() {
	}
}
