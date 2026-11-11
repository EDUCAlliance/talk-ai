<template>
	<component :is="icon" :size="size" />
</template>

<script>
import IconGroup from 'vue-material-design-icons/AccountMultipleOutline.vue'
import IconTeam from 'vue-material-design-icons/AccountGroupOutline.vue'
import IconUser from 'vue-material-design-icons/AccountOutline.vue'
import IconAlert from 'vue-material-design-icons/AlertCircleOutline.vue'
import IconLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconRight from 'vue-material-design-icons/ArrowRight.vue'
import IconBook from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconChevron from 'vue-material-design-icons/ChevronRight.vue'
import IconClock from 'vue-material-design-icons/ClockOutline.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconSparkle from 'vue-material-design-icons/CreationOutline.vue'
import IconSidebar from 'vue-material-design-icons/DockRight.vue'
import IconMore from 'vue-material-design-icons/DotsHorizontal.vue'
import IconGlobe from 'vue-material-design-icons/Earth.vue'
import IconEye from 'vue-material-design-icons/EyeOutline.vue'
import IconFile from 'vue-material-design-icons/FileDocumentOutline.vue'
import IconFolder from 'vue-material-design-icons/FolderOutline.vue'
import IconImage from 'vue-material-design-icons/ImageOutline.vue'
import IconInfo from 'vue-material-design-icons/InformationOutline.vue'
import IconLink from 'vue-material-design-icons/LinkVariant.vue'
import IconLock from 'vue-material-design-icons/LockOutline.vue'
import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconChat from 'vue-material-design-icons/MessageOutline.vue'
import IconMic from 'vue-material-design-icons/MicrophoneOutline.vue'
import IconPencil from 'vue-material-design-icons/PencilOutline.vue'
import IconPin from 'vue-material-design-icons/PinOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconRobot from 'vue-material-design-icons/RobotOutline.vue'
import IconCap from 'vue-material-design-icons/SchoolOutline.vue'
import IconShield from 'vue-material-design-icons/ShieldCheckOutline.vue'
import IconTrash from 'vue-material-design-icons/TrashCanOutline.vue'
import IconUpload from 'vue-material-design-icons/TrayArrowUp.vue'
import IconTool from 'vue-material-design-icons/WrenchOutline.vue'

const ICONS = {
	alert: IconAlert,
	book: IconBook,
	cap: IconCap,
	chat: IconChat,
	check: IconCheck,
	chevron: IconChevron,
	clock: IconClock,
	close: IconClose,
	copy: IconCopy,
	eye: IconEye,
	file: IconFile,
	folder: IconFolder,
	globe: IconGlobe,
	group: IconGroup,
	image: IconImage,
	info: IconInfo,
	left: IconLeft,
	link: IconLink,
	lock: IconLock,
	mic: IconMic,
	more: IconMore,
	pencil: IconPencil,
	pin: IconPin,
	plus: IconPlus,
	refresh: IconRefresh,
	right: IconRight,
	robot: IconRobot,
	search: IconSearch,
	shield: IconShield,
	sidebar: IconSidebar,
	sparkle: IconSparkle,
	team: IconTeam,
	tool: IconTool,
	trash: IconTrash,
	upload: IconUpload,
	user: IconUser,
}

// One place for the icons of the app page, addressed by what they mean. Decorative: the label stands next to them.
export default {
	name: 'TalkAiIcon',
	props: {
		name: { type: String, required: true },
		size: { type: Number, default: 18 },
	},
	computed: {
		icon() {
			return ICONS[this.name] ?? IconInfo
		},
	},
}
</script>
