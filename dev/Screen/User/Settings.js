import { Capa, KeyState } from 'Common/Enums';
import { keyScope, leftPanelType, leftPanelDisabled, Settings } from 'Common/Globals';
import { runSettingsViewModelHooks } from 'Common/Plugins';
import { initOnStartOrLangChange, i18n } from 'Common/Translator';

import AppStore from 'Stores/User/App';
import AccountStore from 'Stores/User/Account';
import { ThemeStore } from 'Stores/Theme';

import { AbstractSettingsScreen, settingsAddViewModel } from 'Screen/AbstractSettings';

import { GeneralUserSettings } from 'Settings/User/General';
import { ContactsUserSettings } from 'Settings/User/Contacts';
import { AccountsUserSettings } from 'Settings/User/Accounts';
import { FiltersUserSettings } from 'Settings/User/Filters';
import { SecurityUserSettings } from 'Settings/User/Security';
import { TemplatesUserSettings } from 'Settings/User/Templates';
import { FoldersUserSettings } from 'Settings/User/Folders';
import { ThemesUserSettings } from 'Settings/User/Themes';
import { OpenPgpUserSettings } from 'Settings/User/OpenPgp';

import { SystemDropDownSettingsUserView } from 'View/User/Settings/SystemDropDown';
import { MenuSettingsUserView } from 'View/User/Settings/Menu';
import { PaneSettingsUserView } from 'View/User/Settings/Pane';

export class SettingsUserScreen extends AbstractSettingsScreen {
	constructor() {
		super([SystemDropDownSettingsUserView, MenuSettingsUserView, PaneSettingsUserView]);

		initOnStartOrLangChange(
			() => this.sSettingsTitle = i18n('TITLES/SETTINGS'),
			() => this.setSettingsTitle()
		);
	}

	/**
	 * @param {Function=} fCallback
	 */
	setupSettings(fCallback = null) {
		if (!Settings.capa(Capa.Settings)) {
			fCallback && fCallback();

			return false;
		}

		settingsAddViewModel(GeneralUserSettings, 'SettingsGeneral', 'SETTINGS_LABELS/LABEL_GENERAL_NAME', 'general', true);

		if (AppStore.contactsIsAllowed()) {
			settingsAddViewModel(ContactsUserSettings, 'SettingsContacts', 'SETTINGS_LABELS/LABEL_CONTACTS_NAME', 'contacts');
		}

		if (Settings.capa(Capa.AdditionalAccounts) || Settings.capa(Capa.Identities)) {
			settingsAddViewModel(
				AccountsUserSettings,
				'SettingsAccounts',
				Settings.capa(Capa.AdditionalAccounts)
					? 'SETTINGS_LABELS/LABEL_ACCOUNTS_NAME'
					: 'SETTINGS_LABELS/LABEL_IDENTITIES_NAME',
				'accounts'
			);
		}

		if (Settings.capa(Capa.Sieve)) {
			settingsAddViewModel(FiltersUserSettings, 'SettingsFilters', 'SETTINGS_LABELS/LABEL_FILTERS_NAME', 'filters');
		}

		if (Settings.capa(Capa.AutoLogout) || Settings.capa(Capa.TwoFactor)) {
			settingsAddViewModel(SecurityUserSettings, 'SettingsSecurity', 'SETTINGS_LABELS/LABEL_SECURITY_NAME', 'security');
		}

		if (Settings.capa(Capa.Templates)) {
			settingsAddViewModel(
				TemplatesUserSettings,
				'SettingsTemplates',
				'SETTINGS_LABELS/LABEL_TEMPLATES_NAME',
				'templates'
			);
		}

		if (Settings.capa(Capa.Folders)) {
			settingsAddViewModel(FoldersUserSettings, 'SettingsFolders', 'SETTINGS_LABELS/LABEL_FOLDERS_NAME', 'folders');
		}

		if (Settings.capa(Capa.Themes)) {
			settingsAddViewModel(ThemesUserSettings, 'SettingsThemes', 'SETTINGS_LABELS/LABEL_THEMES_NAME', 'themes');
		}

		if (Settings.capa(Capa.OpenPGP)) {
			settingsAddViewModel(OpenPgpUserSettings, 'SettingsOpenPGP', 'OpenPGP', 'openpgp');
		}

		runSettingsViewModelHooks(false);

		fCallback && fCallback();

		return true;
	}

	onShow() {
		this.setSettingsTitle();
		keyScope(KeyState.Settings);
		leftPanelType('');

		ThemeStore.isMobile() && leftPanelDisabled(true);
	}

	setSettingsTitle() {
		const sEmail = AccountStore.email();
		rl.setWindowTitle((sEmail ? sEmail + ' - ' :  '') + this.sSettingsTitle);
	}
}
