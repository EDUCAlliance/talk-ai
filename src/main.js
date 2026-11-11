import './utils/ensureOcFilePath.js'
import '@nextcloud/dialogs/style.css'
import './styles/talkai.scss'

import App from './views/App.vue'
import { createApp } from 'vue'
import { installL10n } from './l10n.js'
import router from './router.js'
import { syncEducAiAppIconFromSettings } from './utils/appIconRuntime.js'

const app = createApp(App)
installL10n(app)
app.use(router)
app.mount('#content')
syncEducAiAppIconFromSettings()
