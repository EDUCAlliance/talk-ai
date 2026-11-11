import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { createTalkAiStore } from './createTalkAiStore.js'

/** The one store of the app page. */
export const store = createTalkAiStore({ http: axios, url: generateUrl })
