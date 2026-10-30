/* eslint-disable no-multiple-empty-lines */
import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import { createApp } from 'vue'
import PersonalSettings from './views/PersonalSettings.vue'
import { installL10n } from './l10n.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

const app = createApp(PersonalSettings)
installL10n(app)
app.mount('#educai-personal-root')
syncEducAiAppIconFromSettings()
