<template>
	<NcSettingsSection :name="APP_DISPLAY_NAME"
		:description="t('educai', 'Your bots, the bots you can use and your activity now live on one page.')">
		<div class="educai-personal-pointer">
			<NcButton variant="primary" :href="appUrl('my-bots')">
				{{ t('educai', 'Open {appName}', { appName: APP_DISPLAY_NAME }) }}
			</NcButton>
			<NcButton :href="appUrl('activity')">
				{{ t('educai', 'Activity') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { APP_DISPLAY_NAME } from '../branding.js'

// Creating, editing and reviewing bots moved to the app page. Old bookmarks land here and find the way.
export default {
	name: 'PersonalSettings',

	components: { NcButton, NcSettingsSection },

	data() {
		return { APP_DISPLAY_NAME }
	},

	methods: {
		appUrl(view) {
			return generateUrl('/apps/educai/') + '#/' + view
		},
	},
}
</script>

<style scoped>
.educai-personal-pointer {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
