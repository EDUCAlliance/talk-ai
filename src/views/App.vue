<template>
	<NcContent app-name="educai">
		<NcAppContent>
			<PublicBotList />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import PublicBotList from '../components/PublicBotList.vue'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppContent,
		PublicBotList,
	},
}
</script>

<style scoped lang="scss">
// nothing yet
</style>
