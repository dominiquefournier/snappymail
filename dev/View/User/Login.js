import ko from 'ko';

import {
	StorageResultType,
	Notification
} from 'Common/Enums';

import { ClientSideKeyName } from 'Common/EnumsUser';

import { getNotification, getNotificationFromResponse, reload as translatorReload, convertLangName } from 'Common/Translator';

import AppStore from 'Stores/User/App';
import { LanguageStore } from 'Stores/Language';

import * as Local from 'Storage/Client';

import Remote from 'Remote/User/Fetch';

import { decorateKoCommands, showScreenPopup } from 'Knoin/Knoin';
import { AbstractViewCenter } from 'Knoin/AbstractViews';

import { Settings } from 'Common/Globals';

import { LanguagesPopupView } from 'View/Popup/Languages';

const
	LoginSignMeType = {
		DefaultOff: 0,
		DefaultOn: 1,
		Unused: 2
	},

	LoginSignMeTypeAsString = {
		DefaultOff: 'defaultoff',
		DefaultOn: 'defaulton',
		Unused: 'unused'
	};


class LoginUserView extends AbstractViewCenter {
	constructor() {
		super('User/Login', 'Login');

		this.hideSubmitButton = Settings.app('hideSubmitButton');

		this.addObservables({
			loadingDesc: Settings.get('LoadingDescription'),

			email: '',
			password: '',
			signMe: false,
			additionalCode: '',

			emailError: false,
			passwordError: false,

			formHidden: false,

			submitRequest: false,
			submitError: '',
			submitErrorAddidional: '',

			langRequest: false,

			additionalCodeError: false,
			additionalCodeSignMe: false,
			additionalCodeVisibility: false,
			signMeType: LoginSignMeType.Unused
		});

		this.forgotPasswordLinkUrl = Settings.app('forgotPasswordLinkUrl');
		this.registrationLinkUrl = Settings.app('registrationLinkUrl');

		this.formError = ko.observable(false).extend({ falseTimeout: 500 });

		this.allowLanguagesOnLogin = !!Settings.get('AllowLanguagesOnLogin');

		this.language = LanguageStore.language;
		this.languages = LanguageStore.languages;

		this.bSendLanguage = false;

		this.addComputables({

			languageFullName: () => convertLangName(this.language()),

			signMeVisibility: () => LoginSignMeType.Unused !== this.signMeType()
		});

		this.addSubscribables({
			email: () => {
				this.emailError(false);
				this.additionalCode('');
				this.additionalCodeVisibility(false);
			},

			password: () => this.passwordError(false),

			additionalCode: () => this.additionalCodeError(false),
			additionalCodeError: bV => this.formError(!!bV),
			additionalCodeVisibility: () => this.additionalCodeError(false),

			emailError: bV => this.formError(!!bV),

			passwordError: bV => this.formError(!!bV),

			submitError: value => value || this.submitErrorAddidional(''),

			signMeType: iValue => this.signMe(LoginSignMeType.DefaultOn === iValue)
		});

		if (Settings.get('AdditionalLoginError') && !this.submitError()) {
			this.submitError(Settings.get('AdditionalLoginError'));
		}

		decorateKoCommands(this, {
			submitCommand: self => !self.submitRequest()
		});
	}

	windowOpenFeatures(wh) {
		return `left=200,top=100,width=${wh},height=${wh},menubar=no,status=no,resizable=yes,scrollbars=yes`;
	}

	submitCommand() {
		this.emailError(false);
		this.passwordError(false);

		let error;
		if (this.additionalCodeVisibility()) {
			this.additionalCodeError(false);
			if (!this.additionalCode().trim()) {
				this.additionalCodeError(true);
				error = '.inputAdditionalCode';
			}
		}
		if (!this.password().trim()) {
			this.passwordError(true);
			error = '#RainLoopPassword';
		}
		if (!this.email().trim()) {
			this.emailError(true);
			error = '#RainLoopEmail';
		}
		if (error) {
			this.querySelector(error).focus();
			return false;
		}

		this.submitRequest(true);

		const fLoginRequest = (sLoginPassword) => {
			Remote.login(
				(sResult, oData) => {
					if (StorageResultType.Success === sResult && oData && 'Login' === oData.Action) {
						if (oData.Result) {
							if (oData.TwoFactorAuth) {
								this.additionalCode('');
								this.additionalCodeVisibility(true);
								this.submitRequest(false);

								setTimeout(() => this.querySelector('.inputAdditionalCode').focus(), 100);
							} else {
								rl.route.reload();
							}
						} else if (oData.ErrorCode) {
							this.submitRequest(false);
							if ([Notification.InvalidInputArgument].includes(oData.ErrorCode)) {
								oData.ErrorCode = Notification.AuthError;
							}

							this.submitError(getNotificationFromResponse(oData));

							if (!this.submitError()) {
								this.submitError(getNotification(Notification.UnknownError));
							} else if (oData.ErrorMessageAdditional) {
								this.submitErrorAddidional(oData.ErrorMessageAdditional);
							}
						} else {
							this.submitRequest(false);
						}
					} else {
						this.submitRequest(false);
						this.submitError(getNotification(Notification.UnknownError));
					}
				},
				this.email(),
				'',
				sLoginPassword,
				!!this.signMe(),
				this.bSendLanguage ? this.language() : '',
				this.additionalCodeVisibility() ? this.additionalCode() : '',
				this.additionalCodeVisibility() ? !!this.additionalCodeSignMe() : false
			);

			Local.set(ClientSideKeyName.LastSignMe, this.signMe() ? '-1-' : '-0-');
		};

		fLoginRequest(this.password());

		return true;
	}

	onShow() {
		rl.route.off();
	}

	onBuild() {
		const signMeLocal = Local.get(ClientSideKeyName.LastSignMe),
			signMe = (Settings.get('SignMe') || 'unused').toLowerCase();

		switch (signMe) {
			case LoginSignMeTypeAsString.DefaultOff:
			case LoginSignMeTypeAsString.DefaultOn:
				this.signMeType(
					LoginSignMeTypeAsString.DefaultOn === signMe ? LoginSignMeType.DefaultOn : LoginSignMeType.DefaultOff
				);

				switch (signMeLocal) {
					case '-1-':
						this.signMeType(LoginSignMeType.DefaultOn);
						break;
					case '-0-':
						this.signMeType(LoginSignMeType.DefaultOff);
						break;
					// no default
				}

				break;
			case LoginSignMeTypeAsString.Unused:
			default:
				this.signMeType(LoginSignMeType.Unused);
				break;
		}

		this.email(AppStore.devEmail);
		this.password(AppStore.devPassword);

		setTimeout(() => {
			LanguageStore.language.subscribe((value) => {
				this.langRequest(true);

				translatorReload(false, value).then(
					() => {
						this.langRequest(false);
						this.bSendLanguage = true;
					},
					() => {
						this.langRequest(false);
					}
				);
			});
		}, 50);
	}

	submitForm() {
		this.submitCommand();
	}

	selectLanguage() {
		showScreenPopup(LanguagesPopupView, [this.language, this.languages(), LanguageStore.userLanguage()]);
	}
}

export { LoginUserView };
