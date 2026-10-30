<template>
	<NcContent app-name="educai">
		<NcAppContent>
			<PublicBotList />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
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
