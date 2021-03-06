import ko from 'ko';

import { Capa, KeyState } from 'Common/Enums';
import { Focused } from 'Common/EnumsUser';
import { leftPanelDisabled, moveAction, Settings } from 'Common/Globals';
import { mailBox, settings } from 'Common/Links';
import { setFolderHash } from 'Common/Cache';

import AppStore from 'Stores/User/App';
import SettingsStore from 'Stores/User/Settings';
import FolderStore from 'Stores/User/Folder';
import MessageStore from 'Stores/User/Message';
import { ThemeStore } from 'Stores/Theme';

import { showScreenPopup } from 'Knoin/Knoin';
import { AbstractViewLeft } from 'Knoin/AbstractViews';

import { ComposePopupView } from 'View/Popup/Compose';
import { FolderCreatePopupView } from 'View/Popup/FolderCreate';
import { ContactsPopupView } from 'View/Popup/Contacts';

export class FolderListMailBoxUserView extends AbstractViewLeft {
	constructor() {
		super('User/MailBox/FolderList', 'MailFolderList');

		this.oContentScrollable = null;

		this.composeInEdit = AppStore.composeInEdit;

		this.messageList = MessageStore.messageList;
		this.folderList = FolderStore.folderList;
		this.folderListSystem = FolderStore.folderListSystem;
		this.foldersChanging = FolderStore.foldersChanging;

		this.moveAction = moveAction;

		this.foldersListWithSingleInboxRootFolder = FolderStore.foldersListWithSingleInboxRootFolder;

		this.leftPanelDisabled = leftPanelDisabled;

		this.allowComposer = !!Settings.capa(Capa.Composer);
		this.allowContacts = !!AppStore.contactsIsAllowed();
		this.allowFolders = !!Settings.capa(Capa.Folders);

		this.folderListFocused = ko.computed(() => Focused.FolderList === AppStore.focusedState());

		this.isInboxStarred = ko.computed(
			() =>
				FolderStore.currentFolder() &&
				FolderStore.currentFolder().isInbox() &&
				MessageStore.messageListSearch().trim().includes('is:flagged')
		);
	}

	onBuild(dom) {
		const qs = s => dom.querySelector(s),
			eqs = (ev, s) => ev.target.closestWithin(s, dom),
			fSelectFolder = (el, event, starred) => {
				const isMove = moveAction();
				ThemeStore.isMobile() && leftPanelDisabled(true);

				event.preventDefault();

				if (starred) {
					event.stopPropagation();
				}

				const folder = ko.dataFor(el);
				if (folder) {
					if (isMove) {
						moveAction(false);
						rl.app.moveMessagesToFolder(
							FolderStore.currentFolderFullNameRaw(),
							MessageStore.messageListCheckedOrSelectedUidsWithSubMails(),
							folder.fullNameRaw,
							event.ctrlKey
						);
					} else {
						if (!SettingsStore.usePreviewPane()) {
							MessageStore.message(null);
						}

						if (folder.fullNameRaw === FolderStore.currentFolderFullNameRaw()) {
							setFolderHash(folder.fullNameRaw, '');
						}

						rl.route.setHash(starred
							? mailBox(folder.fullNameHash, 1, 'is:flagged')
							: mailBox(folder.fullNameHash)
						);
					}

					AppStore.focusedState(Focused.MessageList);
				}
			};

		this.oContentScrollable = qs('.b-content');

		dom.addEventListener('click', event => {
			let el = event.target.closest('.e-collapsed-sign');
			if (el) {
				const folder = ko.dataFor(el);
				if (folder) {
					const collapsed = folder.collapsed();
					rl.app.setExpandedFolder(folder.fullNameHash, collapsed);

					folder.collapsed(!collapsed);
					event.preventDefault();
					event.stopPropagation();
					return;
				}
			}

			el = eqs(event, '.b-folders .e-item .e-link.selectable .inbox-star-icon');
			el && fSelectFolder(el, event, !this.isInboxStarred());

			el = eqs(event, '.b-folders .e-item .e-link.selectable');
			el && fSelectFolder(el, event, false);
		});

		shortcuts.add('arrowup,arrowdown', '', KeyState.FolderList, event => {
			let items = [], index = 0;
			dom.querySelectorAll('.b-folders .e-item .e-link:not(.hidden)').forEach(node => {
				if (node.offsetHeight || node.getClientRects().length) {
					items.push(node);
					if (node.matches('.focused')) {
						node.classList.remove('focused');
						index = items.length - 1;
					}
				}
			});
			if (items.length) {
				if ('ArrowUp' === event.key) {
					index && --index;
				} else if (index < items.length - 1) {
					++index;
				}
				items[index].classList.add('focused');
				this.scrollToFocused();
			}

			return false;
		});

		shortcuts.add('enter,open', '', KeyState.FolderList, () => {
			const item = qs('.b-folders .e-item .e-link:not(.hidden).focused');
			if (item) {
				AppStore.focusedState(Focused.MessageList);
				item.click();
			}

			return false;
		});

		shortcuts.add('space', '', KeyState.FolderList, () => {
			const item = qs('.b-folders .e-item .e-link:not(.hidden).focused'),
				folder = item && ko.dataFor(item);
			if (folder) {
				const collapsed = folder.collapsed();
				rl.app.setExpandedFolder(folder.fullNameHash, collapsed);
				folder.collapsed(!collapsed);
			}

			return false;
		});

//		shortcuts.add('tab', 'shift', KeyState.FolderList, () => {
		shortcuts.add('escape,tab,arrowright', '', KeyState.FolderList, () => {
			AppStore.focusedState(Focused.MessageList);
			moveAction(false);
			return false;
		});

		AppStore.focusedState.subscribe(value => {
			let el = qs('.b-folders .e-item .e-link.focused');
			el && qs('.b-folders .e-item .e-link.focused').classList.remove('focused');
			if (Focused.FolderList === value) {
				el = qs('.b-folders .e-item .e-link.selected');
				el && qs('.b-folders .e-item .e-link.selected').classList.add('focused');
			}
		});
	}

	scrollToFocused() {
		const scrollable = this.oContentScrollable;
		if (scrollable) {
			let block, focused = scrollable.querySelector('.e-item .e-link.focused');
			if (focused) {
				const fRect = focused.getBoundingClientRect(),
					sRect = scrollable.getBoundingClientRect();
				if (fRect.top < sRect.top) {
					block = 'start';
				} else if (fRect.bottom > sRect.bottom) {
					block = 'end';
				}
				block && focused.scrollIntoView(block === 'start');
			}
		}
	}

	composeClick() {
		if (Settings.capa(Capa.Composer)) {
			showScreenPopup(ComposePopupView);
		}
	}

	createFolder() {
		showScreenPopup(FolderCreatePopupView);
	}

	configureFolders() {
		rl.route.setHash(settings('folders'));
	}

	contactsClick() {
		if (this.allowContacts) {
			showScreenPopup(ContactsPopupView);
		}
	}
}
