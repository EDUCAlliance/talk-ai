import { loadState } from '@nextcloud/initial-state'

// Server-provided state is available on the app, settings and Talk picker pages.
// Values are immutable for this page load; an OCC change takes effect on reload.
const branding = loadState('educai', 'branding', {})

/** User-facing product name (UI, Talk bot name). */
export const APP_DISPLAY_NAME = branding.displayName || 'Talk AI'

/** Root folder for bot wikis in user storage. MUST NOT change on existing installs. */
export const WIKI_ROOT_FOLDER = branding.wikiRootFolder || 'Talk AI'
