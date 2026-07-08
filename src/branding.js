/**
 * Central branding constants.
 *
 * The private EDUC build overrides these values to 'EDUC AI'; the public
 * "Talk AI" build ships neutral values. Keep in sync with the PHP constants
 * in lib/AppInfo/Application.php (APP_DISPLAY_NAME / WIKI_ROOT_FOLDER).
 */

/** User-facing product name (UI, Talk bot name). */
export const APP_DISPLAY_NAME = 'Talk AI'

/** Root folder for bot wikis in user storage. MUST NOT change on existing installs. */
export const WIKI_ROOT_FOLDER = 'Talk AI'
