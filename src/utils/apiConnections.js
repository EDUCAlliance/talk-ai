import { t } from '../l10n.js'
import { getApiErrorMessage } from './apiError.js'

export const CONNECTIONS_URL = '/apps/educai/api/v1/admin/api-connections'
export const REVISIONS_URL = '/apps/educai/api/v1/admin/api-revisions'
export const PRESETS_URL = '/apps/educai/api/v1/admin/api-presets'
export const CATALOGUE_SOURCE_URL = '/apps/educai/api/v1/admin/api-catalogue-source'
export const DATA_VISIBILITY = 'shared_for_bot_audience'
export const BOT_VISIBILITIES = ['personal', 'groups', 'teams', 'global']
export const PAGING_KEYS = ['has_more', 'next_cursor', 'next_offset', 'total']
const API_TOOL = /^api_[1-9]\d{0,9}_[1-9]\d{0,9}$/

export function isApiToolName(name) {
	return typeof name === 'string' && API_TOOL.test(name)
}

export function visibilityLabel(visibility) {
	return {
		personal: t('educai', 'Personal'),
		groups: t('educai', 'Groups'),
		teams: t('educai', 'Teams'),
		global: t('educai', 'Global'),
	}[visibility] ?? visibility
}

export function authModeLabel(mode) {
	return {
		none: t('educai', 'No authentication'),
		bearer: t('educai', 'Bearer token'),
		api_key_header: t('educai', 'API key in a header'),
	}[mode] ?? mode
}

/**
 * Localized explanation of a stable server code, or null when unknown.
 *
 * @param {string} code Error, problem, blocker or note code
 * @return {string|null}
 */
export function codeText(code) {
	return CODE_TEXTS()[code] ?? null
}

const CODE_TEXTS = () => ({
	// Connection settings
	name_missing: t('educai', 'Enter a name for the connection.'),
	base_url_invalid: t('educai', 'Enter the API base address as an absolute HTTPS address without query or fragment.'),
	base_url_https_required: t('educai', 'The API base address must use HTTPS.'),
	base_url_private_host: t('educai', 'The API base address points to a local or private network, which this server does not allow.'),
	base_url_missing: t('educai', 'Set the API base address of the connection.'),
	base_url_changed: t('educai', 'The base address changed after approval. Approve a revision again.'),
	auth_changed: t('educai', 'The authentication changed after approval. Approve a revision again.'),
	auth_mode_invalid: t('educai', 'Choose a supported authentication.'),
	auth_header_invalid: t('educai', 'Enter the header name the API expects for the key.'),
	credential_invalid: t('educai', 'The credential is empty, too long or contains control characters.'),
	data_visibility_missing: t('educai', 'Confirm who may see the API data before approving or enabling the connection.'),
	data_visibility_invalid: t('educai', 'Unknown data visibility.'),
	assign_policy_invalid: t('educai', 'Choose who may give these API tools to bots.'),
	assign_groups_missing: t('educai', 'Choose at least one group that may assign this API.'),
	assign_groups_invalid: t('educai', 'The group selection is invalid.'),
	bot_visibilities_invalid: t('educai', 'Choose at least one bot visibility this API may be used with.'),
	connection_not_found: t('educai', 'The API connection no longer exists. Reload the page.'),
	connection_disabled: t('educai', 'The API connection is disabled.'),
	// Presets and the course catalogue source
	preset_not_found: t('educai', 'This preset does not exist.'),
	preset_invalid: t('educai', 'The preset shipped with the app is damaged. Reinstall the app.'),
	catalogue_source_unavailable: t('educai', 'The connection cannot serve the course list yet: approve its course list operation and enable the connection first.'),
	catalogue_source_in_use: t('educai', 'The course catalogue reads through this connection. Switch the catalogue source first.'),
	audience_not_instance_wide: t('educai', 'Not released for every bot visibility. Allow personal, group, team and global bots for this connection first.'),
	operation_missing: t('educai', 'The approved document does not contain this operation.'),
	operation_removed: t('educai', 'The operation was removed from the document.'),
	// Import
	document_missing: t('educai', 'Choose a document file or enter its address.'),
	document_fetch_failed: t('educai', 'The document could not be downloaded.'),
	document_unchanged: t('educai', 'This document is identical to the approved revision.'),
	document_not_json: t('educai', 'The document is not JSON. Only OpenAPI 3.0 JSON documents are supported.'),
	document_invalid_json: t('educai', 'The document is not valid JSON.'),
	yaml_not_supported: t('educai', 'YAML documents are not supported yet. Convert the document to JSON.'),
	unsupported_version: t('educai', 'Only OpenAPI 3.0 documents are supported.'),
	document_invalid: t('educai', 'The document is not a valid OpenAPI 3.0 description.'),
	document_too_large: t('educai', 'The document is larger than 2 MiB.'),
	document_too_deep: t('educai', 'The document is nested too deeply.'),
	document_too_complex: t('educai', 'The document is too complex to process safely.'),
	document_too_many_operations: t('educai', 'The document describes too many operations.'),
	ref_external: t('educai', 'The document refers to external files or addresses, which are not loaded.'),
	ref_not_found: t('educai', 'The document refers to a definition that does not exist.'),
	ref_invalid: t('educai', 'The document contains an invalid reference.'),
	ref_cycle: t('educai', 'The document contains circular references.'),
	source_invalid: t('educai', 'Unknown document source.'),
	// Revisions
	revision_not_found: t('educai', 'The document revision no longer exists. Reload the page.'),
	revision_not_draft: t('educai', 'This revision is no longer a draft. Reload the page.'),
	revision_not_editable: t('educai', 'Only drafts can be edited. Reload the page.'),
	revision_not_approved: t('educai', 'Only operations of the approved revision can be revoked.'),
	revision_not_testable: t('educai', 'Only drafts and the approved revision can be tested.'),
	revision_outdated: t('educai', 'This draft refers to operations that were removed meanwhile. Import the document again.'),
	no_operation_enabled: t('educai', 'Offer at least one operation before approving.'),
	operations_not_approvable: t('educai', 'Some offered operations cannot be approved yet. Check the marked operations.'),
	no_approved_revision: t('educai', 'Approve a document revision before enabling the connection.'),
	operation_unknown: t('educai', 'The operation is not part of this revision. Reload the page.'),
	operation_not_testable: t('educai', 'Settle the review of this operation before testing it.'),
	// Per-operation review problems
	operation_not_supported: t('educai', 'This operation cannot be offered.'),
	operation_not_enabled: t('educai', 'Not offered.'),
	read_not_confirmed: t('educai', 'Confirm that the operation only reads data.'),
	operation_revoked: t('educai', 'Revoked.'),
	label_missing: t('educai', 'Enter a label.'),
	server_override_not_approved: t('educai', 'This operation uses its own server; the connection base address is not one of them.'),
	auth_not_chosen: t('educai', 'Choose the authentication this operation uses.'),
	auth_mode_mismatch: t('educai', 'The chosen authentication does not match the connection.'),
	auth_header_mismatch: t('educai', 'The API key header of the document differs from the connection.'),
	credential_missing: t('educai', 'The connection has no stored credential.'),
	auth_choice_invalid: t('educai', 'The authentication choice is invalid.'),
	fixed_invalid: t('educai', 'Fixed values must be query parameters.'),
	fixed_parameter_unknown: t('educai', 'A fixed value names a parameter the operation does not have.'),
	fixed_value_invalid: t('educai', 'A fixed value does not match the parameter definition.'),
	label_invalid: t('educai', 'The label is invalid.'),
	description_invalid: t('educai', 'The description is invalid.'),
	mapping_invalid: t('educai', 'The result mapping is invalid.'),
	mapping_records_invalid: t('educai', 'The records location must be a JSON pointer such as /data, or empty for the whole response.'),
	mapping_id_invalid: t('educai', 'The ID field must be a JSON pointer such as /id.'),
	mapping_title_invalid: t('educai', 'The title field must be a JSON pointer such as /title.'),
	mapping_fields_invalid: t('educai', 'Up to 12 extra fields are possible.'),
	mapping_field_key_invalid: t('educai', 'Field names use lowercase letters, digits and underscores, are unique and are not id, title or url.'),
	mapping_field_pointer_invalid: t('educai', 'Each extra field needs a JSON pointer such as /summary.'),
	mapping_link_invalid: t('educai', 'The source link settings are invalid. Templates must be HTTPS and use {id}.'),
	mapping_link_origin_missing: t('educai', 'A link field needs at least one accepted HTTPS origin.'),
	mapping_paging_invalid: t('educai', 'Paging fields must be JSON pointers.'),
	// Compiler blockers and notes
	method_not_supported: t('educai', 'Only GET operations can be offered.'),
	possibly_mutating: t('educai', 'The name or description suggests the operation changes data.'),
	request_body_unsupported: t('educai', 'Request bodies are not supported.'),
	no_success_response: t('educai', 'The document declares no successful response.'),
	response_not_json: t('educai', 'The successful response is not JSON.'),
	response_unreadable: t('educai', 'The response description could not be read; check the result mapping with a test call.'),
	response_schema_unreadable: t('educai', 'The response schema could not be read; check the result mapping with a test call.'),
	security_unsupported: t('educai', 'The required authentication is not supported (only bearer tokens, API keys in a header or anonymous access).'),
	security_invalid: t('educai', 'The security requirements are invalid.'),
	security_schemes_invalid: t('educai', 'The security schemes are invalid.'),
	server_override: t('educai', 'This operation declares its own server.'),
	server_invalid: t('educai', 'A server address is invalid.'),
	servers_invalid: t('educai', 'The server list is invalid.'),
	server_variable_missing: t('educai', 'A server address uses a variable without default.'),
	operation_deprecated: t('educai', 'The document marks this operation as deprecated.'),
	operation_invalid: t('educai', 'The operation definition is invalid.'),
	operation_id_duplicate: t('educai', 'Several operations use the same operationId; IDs are assigned from method and path instead.'),
	callbacks_ignored: t('educai', 'Callbacks are ignored.'),
	optional_parameter_not_offered: t('educai', 'An optional parameter with unsupported features is not offered to the bot.'),
	path_invalid: t('educai', 'The path is invalid.'),
	path_item_invalid: t('educai', 'The path definition is invalid.'),
	path_template_invalid: t('educai', 'The path template is invalid.'),
	path_parameter_missing: t('educai', 'A path parameter is not defined.'),
	path_parameter_not_required: t('educai', 'A path parameter is not marked as required.'),
	path_parameter_unused: t('educai', 'A path parameter does not occur in the path.'),
	parameters_invalid: t('educai', 'The parameter list is invalid.'),
	parameters_too_many: t('educai', 'The operation has too many parameters.'),
	parameter_invalid: t('educai', 'A parameter definition is invalid.'),
	parameter_duplicate: t('educai', 'A parameter is defined twice.'),
	parameter_deprecated: t('educai', 'A parameter is deprecated.'),
	parameter_location_unsupported: t('educai', 'Only path and query parameters are supported (no headers or cookies).'),
	parameter_name_unsupported: t('educai', 'A parameter name contains unsupported characters.'),
	parameter_style_unsupported: t('educai', 'A parameter uses an unsupported serialization style.'),
	parameter_content_unsupported: t('educai', 'A parameter uses "content" instead of a schema.'),
	parameter_schema_missing: t('educai', 'A parameter has no schema.'),
	parameter_allow_empty_unsupported: t('educai', 'A parameter allows empty values, which is not supported.'),
	parameter_allow_reserved_unsupported: t('educai', 'A parameter allows reserved characters, which is not supported.'),
	parameter_array_in_path_unsupported: t('educai', 'Lists in path parameters are not supported.'),
	schema_invalid: t('educai', 'A parameter schema is invalid.'),
	schema_cycle: t('educai', 'A parameter schema refers to itself.'),
	schema_type_missing: t('educai', 'A parameter schema has no type.'),
	schema_type_unsupported: t('educai', 'A parameter uses an unsupported type.'),
	schema_object_unsupported: t('educai', 'Object parameters are not supported.'),
	schema_nested_array_unsupported: t('educai', 'Nested lists are not supported.'),
	schema_items_missing: t('educai', 'A list parameter does not define its items.'),
	schema_composition_unsupported: t('educai', 'Parameter schemas with allOf, anyOf, oneOf or not are not supported.'),
	schema_keyword_unsupported: t('educai', 'A parameter schema uses an unsupported keyword.'),
	schema_format_unsupported: t('educai', 'A parameter uses an unsupported format.'),
	schema_pattern_unsupported: t('educai', 'Parameter patterns are not supported; the operation would accept values the API rejects.'),
	// Calls
	tool_not_available: t('educai', 'This API tool is currently not available.'),
	invalid_arguments: t('educai', 'The test values do not match the operation.'),
	target_not_allowed: t('educai', 'The address is not allowed by the server network policy.'),
	target_outside_base: t('educai', 'The request would leave the approved API address.'),
	redirect_blocked: t('educai', 'The API answered with a redirect, which is not followed.'),
	auth_rejected: t('educai', 'The API rejected the configured authentication.'),
	not_found: t('educai', 'The API found no matching resource.'),
	rate_limited: t('educai', 'The API rate limit is reached. Try again later.'),
	upstream_error: t('educai', 'The API reported an error.'),
	request_rejected: t('educai', 'The API rejected the request.'),
	timeout: t('educai', 'The API did not answer in time.'),
	transport_error: t('educai', 'The API could not be reached.'),
	response_compressed: t('educai', 'The API sent a compressed response, which is refused.'),
	response_too_large: t('educai', 'The response is larger than allowed.'),
	response_too_deep: t('educai', 'The response is nested too deeply.'),
	response_invalid_json: t('educai', 'The API answered with invalid JSON.'),
	credential_unreadable: t('educai', 'The stored credential cannot be read. Replace it.'),
	api_connection_failed: t('educai', 'The API connection could not be processed. Check the server log for details.'),
})

/**
 * Message for a failed request, preferring the localized text of its code.
 *
 * @param {unknown} error Axios error
 * @param {string} fallback Localized fallback
 * @return {string}
 */
export function errorText(error, fallback) {
	const payload = error?.response?.data ?? error?.data ?? error
	const known = typeof payload?.errorCode === 'string' ? codeText(payload.errorCode) : null
	if (known === null) {
		return getApiErrorMessage(error, fallback)
	}
	const reason = typeof payload.details?.reason === 'string' ? codeText(payload.details.reason) : null
	return reason ? `${known} ${reason}` : known
}

export function emptyConnectionForm() {
	return {
		name: '',
		description: '',
		baseUrl: '',
		authMode: 'none',
		authHeader: '',
		credential: '',
		removeCredential: false,
		credentialSet: false,
		sharedForBotAudience: false,
		assignPolicy: 'admins',
		assignGroups: [],
		botVisibilities: [...BOT_VISIBILITIES],
	}
}

export function connectionForm(connection) {
	return {
		...emptyConnectionForm(),
		name: connection.name ?? '',
		description: connection.description ?? '',
		baseUrl: connection.base_url ?? '',
		authMode: connection.auth_mode ?? 'none',
		authHeader: connection.auth_header ?? '',
		credentialSet: connection.credential_set === true,
		sharedForBotAudience: connection.data_visibility === DATA_VISIBILITY,
		assignPolicy: connection.assign_policy ?? 'admins',
		assignGroups: [...(connection.assign_groups ?? [])],
		botVisibilities: [...(connection.bot_visibilities ?? BOT_VISIBILITIES)],
	}
}

/**
 * Request body for creating or updating a connection. The stored credential
 * is only touched when a new value is entered or removal was chosen.
 *
 * @param {object} form State from connectionForm()
 * @return {object}
 */
export function connectionPayload(form) {
	const payload = {
		name: form.name.trim(),
		description: form.description.trim(),
		base_url: form.baseUrl.trim(),
		auth_mode: form.authMode,
		data_visibility: form.sharedForBotAudience ? DATA_VISIBILITY : null,
		assign_policy: form.assignPolicy,
		assign_groups: form.assignPolicy === 'groups' ? [...form.assignGroups] : [],
		bot_visibilities: BOT_VISIBILITIES.filter(visibility => form.botVisibilities.includes(visibility)),
	}
	if (form.authMode === 'api_key_header') {
		payload.auth_header = form.authHeader.trim()
	}
	if (form.authMode !== 'none') {
		if (form.credential.trim() !== '') {
			payload.credential = { action: 'replace', value: form.credential.trim() }
		} else if (form.removeCredential) {
			payload.credential = { action: 'remove' }
		}
	}
	return payload
}

/**
 * Authentication alternatives the admin can choose for one operation.
 *
 * @param {object} operation Presented operation
 * @return {Array<{key:string,label:string,supported:boolean,reason:string|null}>}
 */
export function authChoices(operation) {
	const security = operation.security ?? {}
	const alternatives = Array.isArray(security.alternatives) ? security.alternatives : []
	if (security.declared !== true || alternatives.length === 0) {
		return [{ key: 'none', label: t('educai', 'No authentication (the document declares none)'), supported: true, reason: null }]
	}
	return alternatives.map(alternative => ({
		key: alternative.key,
		label: alternative.key === 'anonymous'
			? t('educai', 'Anonymous access (offered by the document)')
			: t('educai', '{scheme} ({mode})', { scheme: alternative.scheme ?? alternative.key, mode: authModeLabel(alternative.mode) }),
		supported: alternative.supported === true,
		reason: alternative.supported === true ? null : (codeText(alternative.reason) ?? codeText('security_unsupported')),
	}))
}

/**
 * Editable copy of an operation's review settings.
 *
 * @param {object} operation Presented operation
 * @return {object}
 */
export function reviewState(operation) {
	const config = operation.config ?? {}
	const mapping = config.mapping ?? {}
	const link = mapping.link ?? {}
	return {
		enabled: config.enabled === true,
		readConfirmed: config.read_confirmed === true,
		label: config.label ?? '',
		description: config.description ?? '',
		auth: config.auth ?? '',
		fixed: Object.entries(config.fixed?.query ?? {}).map(([name, value]) => `${name} = ${typeof value === 'string' ? value : JSON.stringify(value)}`).join('\n'),
		records: mapping.records ?? '',
		id: mapping.id ?? '',
		title: mapping.title ?? '',
		fields: (mapping.fields ?? []).map(field => `${field.key} = ${field.pointer}`).join('\n'),
		linkField: link.field ?? '',
		linkTemplate: link.template ?? '',
		linkOrigins: (link.origins ?? []).join('\n'),
		paging: Object.fromEntries(PAGING_KEYS.map(key => [key, mapping.paging?.[key] ?? ''])),
	}
}

const lines = text => String(text ?? '').split('\n').map(line => line.trim()).filter(line => line !== '')

function pairs(text, invalid) {
	const result = []
	const errors = []
	for (const line of lines(text)) {
		const index = line.indexOf('=')
		if (index <= 0) {
			errors.push(invalid(line))
			continue
		}
		result.push([line.slice(0, index).trim(), line.slice(index + 1).trim()])
	}
	return { result, errors }
}

function fixedValue(raw) {
	if (/^(true|false|null|-?\d+(\.\d+)?|\[.*\])$/.test(raw)) {
		try {
			return JSON.parse(raw)
		} catch {
			return raw
		}
	}
	return raw
}

/**
 * Server request for one operation's review state. Line formats that cannot
 * be read are reported instead of being dropped silently.
 *
 * @param {object} state From reviewState()
 * @return {{edit:object,errors:string[]}}
 */
export function reviewEdit(state) {
	const fixed = pairs(state.fixed, line => t('educai', 'Fixed value "{line}" needs the form name = value.', { line }))
	const fields = pairs(state.fields, line => t('educai', 'Extra field "{line}" needs the form name = /pointer.', { line }))
	const paging = {}
	for (const key of PAGING_KEYS) {
		const value = String(state.paging?.[key] ?? '').trim()
		if (value !== '') {
			paging[key] = value
		}
	}
	return {
		edit: {
			enabled: state.enabled,
			read_confirmed: state.readConfirmed,
			label: state.label.trim(),
			description: state.description.trim(),
			...(state.auth ? { auth: state.auth } : {}),
			fixed: { query: Object.fromEntries(fixed.result.map(([name, value]) => [name, fixedValue(value)])) },
			mapping: {
				records: state.records.trim(),
				id: state.id.trim() || null,
				title: state.title.trim() || null,
				fields: fields.result.map(([key, pointer]) => ({ key, pointer })),
				link: { field: state.linkField.trim() || null, template: state.linkTemplate.trim() || null, origins: lines(state.linkOrigins) },
				paging,
			},
		},
		errors: [...fixed.errors, ...fields.errors],
	}
}

export function reviewChanged(state, operation) {
	return JSON.stringify(reviewEdit(state).edit) !== JSON.stringify(reviewEdit(reviewState(operation)).edit)
}

/**
 * Parameters an admin fills in for a test call; server-fixed query values
 * are sent by the server and cannot be entered.
 *
 * @param {object} operation Presented operation
 * @param {object} state From reviewState()
 * @return {object[]}
 */
export function testFields(operation, state) {
	const fixed = new Set(pairs(state.fixed, () => '').result.map(([name]) => name))
	return (operation.parameters ?? []).filter(parameter => !(parameter.in === 'query' && fixed.has(parameter.name)))
}

function coerce(raw, schema) {
	switch (schema?.type) {
	case 'integer':
		return /^-?\d+$/.test(raw) ? Number(raw) : undefined
	case 'number':
		return raw !== '' && Number.isFinite(Number(raw)) ? Number(raw) : undefined
	case 'boolean':
		return raw === 'true' ? true : (raw === 'false' ? false : undefined)
	default:
		return raw
	}
}

/**
 * Test-call arguments from text inputs, typed like the model would send them.
 *
 * @param {object[]} fields From testFields()
 * @param {{[key: string]: string}} values Keyed by "in:name"
 * @return {{arguments:{path:object,query:object},errors:string[]}}
 */
export function testArguments(fields, values) {
	const args = { path: {}, query: {} }
	const errors = []
	for (const field of fields) {
		const raw = String(values[`${field.in}:${field.name}`] ?? '').trim()
		if (raw === '') {
			if (field.required) {
				errors.push(t('educai', '"{name}" is required.', { name: field.name }))
			}
			continue
		}
		let value
		if (field.schema?.type === 'array') {
			const items = raw.split(',').map(item => item.trim()).filter(item => item !== '').map(item => coerce(item, field.schema.items))
			value = items.includes(undefined) ? undefined : items
		} else {
			value = coerce(raw, field.schema)
		}
		if (value === undefined) {
			errors.push(t('educai', '"{name}" does not have the expected type.', { name: field.name }))
			continue
		}
		args[field.in][field.name] = value
	}
	return { arguments: args, errors }
}

export function aspectLabel(aspect) {
	return {
		parameters: t('educai', 'parameters'),
		security: t('educai', 'authentication'),
		servers: t('educai', 'servers'),
		response: t('educai', 'response'),
		blockers: t('educai', 'support'),
		summary: t('educai', 'summary'),
		description: t('educai', 'description'),
		deprecated: t('educai', 'deprecation'),
		other: t('educai', 'other details'),
	}[aspect] ?? aspect
}

/**
 * Readable semantic difference between the approved revision and a draft.
 *
 * @param {object|null} diff Draft diff from the server
 * @param {object|null} active Presented active revision
 * @return {Array<{kind:string,id:number,matchKey:string,label:string,aspects:string[],wasOffered:boolean}>}
 */
export function diffEntries(diff, active) {
	if (!diff || diff.base_revision_id === null) {
		return []
	}
	const offered = new Map((active?.operations ?? []).map(operation => [operation.id, operation.config?.enabled === true && !operation.config?.revoked_at]))
	const label = (id, matchKey) => (active?.operations ?? []).find(operation => operation.id === id)?.config?.label || matchKey
	return [
		...(diff.removed ?? []).map(entry => ({ kind: 'removed', id: entry.id, matchKey: entry.match_key, label: label(entry.id, entry.match_key), aspects: [], wasOffered: offered.get(entry.id) === true })),
		...(diff.changed ?? []).map(entry => ({ kind: 'changed', id: entry.id, matchKey: entry.match_key, label: label(entry.id, entry.match_key), aspects: entry.aspects.map(aspectLabel), wasOffered: offered.get(entry.id) === true })),
		...(diff.added ?? []).map(id => ({ kind: 'added', id, matchKey: '', label: '', aspects: [], wasOffered: false })),
	]
}

/**
 * Why an offered API tool cannot be chosen for a bot with this visibility.
 *
 * @param {object} tool Entry of /api/v1/tools
 * @param {string} visibility Bot visibility in the form
 * @return {string|null}
 */
export function apiToolBlockReason(tool, visibility) {
	const allowed = tool?.api?.bot_visibilities
	if (!Array.isArray(allowed) || allowed.includes(visibility)) {
		return null
	}
	return t('educai', 'Only for bots with visibility: {list}.', { list: allowed.map(visibilityLabel).join(', ') })
}

/**
 * Selected built-in tools that are no longer offered. They stay assigned
 * until removed, so the form must show them instead of hiding them.
 *
 * @param {Array<string|number>} selected Tool keys of the form
 * @param {Set<string>} offered Keys of offered tools
 * @param {{[key: string]: string|null}} labels Stored labels keyed by tool key
 * @return {Array<{key:string,label:string}>}
 */
export function unavailableSelections(selected, offered, labels) {
	return selected
		.filter(key => typeof key === 'string' && key.startsWith('builtin:') && !offered.has(key))
		.map(key => {
			const name = key.slice('builtin:'.length)
			const label = labels[key] || (isApiToolName(name) ? t('educai', 'Removed API tool') : name)
			return { key, label }
		})
}
