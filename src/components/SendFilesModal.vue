<template>
	<div class="zulip-modal-container">
		<NcModal v-if="show"
			size="normal"
			@close="closeModal">
			<div class="zulip-modal-content">
				<h2 class="modal-title">
					<ZulipIcon />
					<span>
						{{ sendType === SEND_TYPE.file.id
							? n('integration_zulip', 'Send file to Zulip', 'Send files to Zulip', files.length)
							: n('integration_zulip', 'Send link to Zulip', 'Send links to Zulip', files.length)
						}}
					</span>
				</h2>
				<span class="field-label">
					<FileIcon />
					<span>
						<strong>
							{{ t('integration_zulip', 'Files') }}
						</strong>
					</span>
				</span>
				<div class="files">
					<div v-for="f in files"
						:key="f.id"
						class="file">
						<NcLoadingIcon v-if="fileStates[f.id] === STATES.IN_PROGRESS"
							:size="20" />
						<CheckCircleIcon v-else-if="fileStates[f.id] === STATES.FINISHED"
							class="check-icon"
							:size="24" />
						<img v-else
							:src="getFilePreviewUrl(f.id, f.type)"
							class="file-image">
						<span class="file-name">
							{{ f.name }}
						</span>
						<div class="spacer" />
						<span class="file-size">
							{{ myHumanFileSize(f.size, true) }}
						</span>
						<NcButton class="remove-file-button"
							:aria-label="t('integration_zulip', 'Remove file from list')"
							@click="onRemoveFile(f.id)">
							<template #icon>
								<CloseIcon :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
				<span class="field-label">
					<PoundBoxIcon />
					<span>
						<strong>
							{{ t('integration_zulip', 'Conversation') }}
						</strong>
					</span>
					<NcLoadingIcon v-if="channels === undefined" :size="20" />
				</span>
				<NcSelect
					v-model="selectedChannel"
					class="channel-select"
					label="name"
					:clearable="false"
					:options="channels"
					:append-to-body="false"
					:placeholder="t('integration_zulip', 'Choose a conversation')"
					input-id="zulip-channel-select"
					@search="query = $event">
					<template #option="option">
						<div class="select-option">
							<LockIcon v-if="option.invite_only"
								:size="20" />
							<EarthIcon v-else-if="option.is_web_public"
								:size="20" />
							<PoundIcon v-else
								:size="20" />
							<NcHighlight
								:text="option.name"
								:search="query"
								class="multiselect-name" />
						</div>
					</template>
					<template #selected-option="option">
						<LockIcon v-if="option.invite_only"
							:size="20" />
						<EarthIcon v-else-if="option.is_web_public"
							:size="20" />
						<PoundIcon v-else
							:size="20" />
						<span class="multiselect-name">
							{{ option.name }}
						</span>
					</template>
				</NcSelect>
				<span v-if="selectedChannel.type === 'channel'"
					class="field-label">
					<PoundIcon />
					<span>
						<strong>
							{{ t('integration_zulip', 'Topic') }}
						</strong>
					</span>
					<NcLoadingIcon v-if="topics === undefined" :size="20" />
				</span>
				<NcSelect v-if="selectedChannel.type === 'channel'"
					v-model="selectedTopic"
					class="channel-select"
					label="name"
					:clearable="false"
					:options="topics"
					:append-to-body="false"
					:placeholder="t('integration_zulip', 'Select a topic')"
					input-id="zulip-channel-select"
					@search="query = $event">
					<template #option="option">
						<div class="select-option">
							<NcHighlight
								:text="option.name"
								:search="query"
								class="multiselect-name" />
						</div>
					</template>
					<template #selected-option="option">
						<span class="multiselect-name">
							{{ option.name }}
						</span>
					</template>
				</NcSelect>
				<div class="advanced-options">
					<span class="field-label">
						<PackageUpIcon />
						<span>
							<strong>
								{{ t('integration_zulip', 'Type') }}
							</strong>
						</span>
					</span>
					<div>
						<NcCheckboxRadioSwitch v-for="(type, key) in SEND_TYPE"
							:key="key"
							:checked.sync="sendType"
							:value="type.id"
							name="send_type_radio"
							type="radio">
							<div class="select-option">
								<component :is="type.icon" :size="20" />
								<span class="option-title">
									{{ type.label }}
								</span>
							</div>
						</NcCheckboxRadioSwitch>
					</div>
					<RadioElementSet v-if="sendType === SEND_TYPE.public_link.id"
						name="perm_radio"
						:options="permissionOptions"
						:value="selectedPermission"
						class="radios"
						@update:value="selectedPermission = $event" />
					<div v-show="sendType === SEND_TYPE.public_link.id"
						class="expiration-field">
						<NcCheckboxRadioSwitch :checked.sync="expirationEnabled">
							{{ t('integration_zulip', 'Set expiration date') }}
						</NcCheckboxRadioSwitch>
						<div class="spacer" />
						<NcDateTimePicker v-show="expirationEnabled"
							id="expiration-datepicker"
							v-model="expirationDate"
							:disabled-date="isDateDisabled"
							:placeholder="t('integration_zulip', 'Expires on')"
							:clearable="true" />
					</div>
					<div v-show="sendType === SEND_TYPE.public_link.id"
						class="password-field">
						<NcCheckboxRadioSwitch :checked.sync="passwordEnabled">
							{{ t('integration_zulip', 'Set link password') }}
						</NcCheckboxRadioSwitch>
						<div class="spacer" />
						<input v-show="passwordEnabled"
							id="password-input"
							v-model="password"
							type="text"
							:placeholder="passwordPlaceholder">
					</div>
					<span class="field-label">
						<CommentIcon />
						<span>
							<strong>
								{{ t('integration_zulip', 'Comment') }}
							</strong>
						</span>
					</span>
					<div class="input-wrapper">
						<input v-model="comment"
							type="text"
							:placeholder="commentPlaceholder">
					</div>
				</div>
				<span v-if="warnAboutSendingDirectories"
					class="warning-container">
					<AlertBoxIcon class="warning-icon" />
					<label>
						{{ t('integration_zulip', 'Directories will be skipped, they can only be sent as links.') }}
					</label>
				</span>
				<div class="zulip-footer">
					<div class="spacer" />
					<NcButton
						:aria-label="t('integration_zulip', 'Cancel')"
						@click="closeModal">
						{{ t('integration_zulip', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						:class="{ loading, okButton: true }"
						:disabled="!canValidate"
						:aria-label="sendType === SEND_TYPE.file.id
							? n('integration_zulip', 'Send file', 'Send files', files.length)
							: n('integration_zulip', 'Send link', 'Send links', files.length)"
						@click="onSendClick">
						<template #icon>
							<SendIcon />
						</template>
						{{ sendType === SEND_TYPE.file.id
							? n('integration_zulip', 'Send file', 'Send files', files.length)
							: n('integration_zulip', 'Send link', 'Send links', files.length) }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcDateTimePicker from '@nextcloud/vue/dist/Components/NcDateTimePicker.js'
import NcHighlight from '@nextcloud/vue/dist/Components/NcHighlight.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AlertBoxIcon from 'vue-material-design-icons/AlertBox.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PackageUpIcon from 'vue-material-design-icons/PackageUp.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PoundIcon from 'vue-material-design-icons/Pound.vue'
import PoundBoxIcon from 'vue-material-design-icons/PoundBox.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { FileType } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { humanFileSize, SEND_TYPE } from '../utils.js'
import ZulipIcon from './icons/ZulipIcon.vue'
import RadioElementSet from './RadioElementSet.vue'

const STATES = {
	IN_PROGRESS: 1,
	FINISHED: 2,
}

export default {
	name: 'SendFilesModal',

	components: {
		ZulipIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcDateTimePicker,
		NcHighlight,
		NcModal,
		RadioElementSet,
		NcLoadingIcon,
		NcButton,
		NcAvatar,
		AlertBoxIcon,
		CheckCircleIcon,
		CloseIcon,
		CommentIcon,
		EarthIcon,
		FileIcon,
		LockIcon,
		PackageUpIcon,
		PoundIcon,
		PoundBoxIcon,
		SendIcon,
		AccountMultiple,
	},

	data() {
		return {
			SEND_TYPE,
			show: false,
			loading: false,
			sendType: SEND_TYPE.file.id,
			comment: '',
			query: '',
			files: [],
			fileStates: {},
			channels: undefined, // undefined means loading
			selectedChannel: null,
			topics: undefined, // undefined means loading
			selectedTopic: null,
			selectedPermission: 'view',
			expirationEnabled: false,
			expirationDate: null,
			passwordEnabled: false,
			password: '',
			passwordPlaceholder: t('integration_zulip', 'password'),
			STATES,
			commentPlaceholder: t('integration_zulip', 'Message to send with the files'),
			permissionOptions: {
				view: { label: t('integration_zulip', 'View only'), icon: EyeIcon },
				edit: { label: t('integration_zulip', 'Edit'), icon: PencilIcon },
			},
		}
	},

	computed: {
		warnAboutSendingDirectories() {
			return this.sendType === SEND_TYPE.file.id && this.files.findIndex((f) => f.type === 'dir') !== -1
		},
		onlyDirectories() {
			return this.files.filter((f) => f.type !== 'dir').length === 0
		},
		canValidate() {
			return this.selectedChannel !== null
				&& (this.sendType !== SEND_TYPE.file.id || !this.onlyDirectories)
				&& this.files.length > 0
		},
	},

	watch: {
		selectedChannel(newChannel, oldChannel) {
			if (newChannel.type === 'channel') {
				this.updateTopics()
			}
		},
	},

	mounted() {
		this.reset()
	},

	methods: {
		reset() {
			this.selectedChannel = null
			this.selectedTopic = null
			this.files = []
			this.fileStates = {}
			this.channels = undefined
			this.topics = undefined
			this.comment = ''
			this.sendType = SEND_TYPE.file.id
			this.selectedPermission = 'view'
			this.expirationEnabled = false
			this.expirationDate = null
			this.passwordEnabled = false
			this.password = null
		},
		showModal() {
			this.show = true
		},
		closeModal() {
			this.show = false
			this.$emit('closed')
			this.reset()
		},
		setFiles(files) {
			this.files = files
		},
		onSendClick() {
			this.loading = true
			this.$emit('validate', {
				filesToSend: [...this.files],
				messageType: this.selectedChannel.type,
				channelId: this.selectedChannel.id,
				channelName: this.selectedChannel.name,
				topicName: this.selectedTopic.name,
				type: this.sendType,
				comment: this.comment,
				permission: this.selectedPermission,
				expirationDate: this.sendType === SEND_TYPE.public_link.id && this.expirationEnabled ? this.expirationDate : null,
				password: this.sendType === SEND_TYPE.public_link.id && this.passwordEnabled ? this.password : null,
			})
		},
		success() {
			this.loading = false
			this.closeModal()
		},
		failure() {
			this.loading = false
		},
		updateChannels() {
			const url = generateUrl('apps/integration_zulip/channels')
			axios.get(url).then((response) => {
				this.channels = response.data ?? []
				this.channels.sort((a, b) => a.name.localeCompare(b.name))
				if (this.channels.length > 0) {
					this.selectedChannel = this.channels[0]
				}
			}).catch((error) => {
				showError(t('integration_zulip', 'Failed to load Zulip channels'))
				console.error(error)
				this.channels = []
			})
		},
		updateTopics() {
			const url = generateUrl(`apps/integration_zulip/channels/${this.selectedChannel.id}/topics`)
			axios.get(url).then((response) => {
				this.topics = response.data ?? []
				this.topics.sort((a, b) => a.name.localeCompare(b.name))
				if (this.topics.length > 0) {
					this.selectedTopic = this.topics[0]
				}
			}).catch((error) => {
				showError(t('integration_zulip', 'Failed to load Zulip topics'))
				console.error(error)
				this.topics = []
			})
		},
		getFilePreviewUrl(fileId, fileType) {
			if (fileType === FileType.Folder) {
				return generateUrl('/apps/theming/img/core/filetypes/folder.svg')
			}
			return generateUrl('/apps/integration_zulip/preview?id={fileId}&x=24&y=24', { fileId })
		},
		fileStarted(id) {
			this.$set(this.fileStates, id, STATES.IN_PROGRESS)
		},
		fileFinished(id) {
			this.$set(this.fileStates, id, STATES.FINISHED)
		},
		getUserIconUrl(zulipUserId) {
			return generateUrl('/apps/integration_zulip/users/{zulipUserId}/image', { zulipUserId })
		},
		isDateDisabled(d) {
			const now = new Date()
			return d <= now
		},
		myHumanFileSize(bytes, approx = false, si = false, dp = 1) {
			return humanFileSize(bytes, approx, si, dp)
		},
		onRemoveFile(fileId) {
			const index = this.files.findIndex((f) => f.id === fileId)
			this.files.splice(index, 1)
		},
		onChannelSelected(selected) {
			if (selected !== null) {
				this.selectedChannel = selected
			}
		},
	},
}
</script>

<style scoped lang="scss">
.zulip-modal-content {
	//width: 100%;
	padding: 16px;
	display: flex;
	flex-direction: column;
	overflow-y: scroll;

	.select-option {
		display: flex;
		align-items: center;
	}

	> *:not(.zulip-footer) {
		margin-bottom: 16px;
	}

	.field-label {
		display: flex;
		align-items: center;
		margin: 12px 0;
		span {
			margin-left: 8px;
		}
	}

	> *:not(.field-label):not(.advanced-options):not(.zulip-footer):not(.warning-container),
	.advanced-options > *:not(.field-label) {
		margin-left: 10px;
	}

	.advanced-options {
		display: flex;
		flex-direction: column;
	}

	.expiration-field {
		margin-top: 8px;
	}

	.password-field,
	.expiration-field {
		display: flex;
		align-items: center;
		> *:first-child {
			margin-right: 20px;
		}
		#expiration-datepicker,
		#password-input {
			width: 250px;
			margin: 0;
		}
	}

	.modal-title {
		display: flex;
		justify-content: center;
		span {
			margin-left: 8px;
		}
	}

	input[type='text'] {
		width: 100%;
	}

	.files {
		display: flex;
		flex-direction: column;
		.file {
			display: flex;
			align-items: center;
			margin: 4px 0;
			height: 40px;

			> *:first-child {
				width: 32px;
			}

			img {
				height: auto;
			}

			.file-name {
				margin-left: 12px;
				text-overflow: ellipsis;
				overflow: hidden;
				white-space: nowrap;
			}

			.file-size {
				white-space: nowrap;
			}

			.check-icon {
				color: var(--color-success);
			}

			.remove-file-button {
				width: 32px !important;
				height: 32px;
				margin-left: 8px;
				min-width: 32px;
				min-height: 32px;
			}
		}
	}

	.radios {
		margin-top: 8px;
		width: 250px;
	}

	.channel-select {
		height: 44px;
	}

	.settings-hint {
		color: var(--color-text-maxcontrast);
		margin: 16px 0 16px 0;
	}

	.multiselect-name {
		margin-left: 8px;
	}

	.option-title {
		margin-left: 8px;
	}
}

.spacer {
	flex-grow: 1;
}

.zulip-footer {
	display: flex;
	> * {
		margin-left: 8px;
	}
}

.warning-container {
	display: flex;
	> label {
		margin-left: 8px;
	}
	.warning-icon {
		color: var(--color-warning);
	}
}
</style>
