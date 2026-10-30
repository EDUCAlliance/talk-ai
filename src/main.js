import './utils/ensureOcFilePath.js'

import App from './views/App.vue'
import { createApp } from 'vue'
import { installL10n } from './l10n.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

const app = createApp(App)
installL10n(app)
app.mount('#content')
syncEducAiAppIconFromSettings()
