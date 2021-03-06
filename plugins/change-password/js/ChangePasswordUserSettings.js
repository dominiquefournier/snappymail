
(rl => {

	if (!rl)
	{
		return;
	}

	let pw_re = [/[^0-9A-Za-z]+/g, /[0-9]+/g, /[A-Z]+/g, /[a-z]+/g],
		getPassStrength = v => {
			let m,
				i = v.length,
				s = i?1:0,
				c = 0,
				ii;
			while (i--) {
				if (v[i] != v[i+1]) {
					++s;
				} else {
					s -= 0.5;
				}
			}
			for (i = 0; i < 4; ++i) {
				m = v.match(pw_re[i]);
				if (m) {
					++c;
					for (ii = 0; ii < m.length; ++ii) {
						if (5 > m[ii].length) {
							++s;
						}
					}
				}
			}
			s = (s / 3 * c);
			return Math.max(0, Math.min(100, s * 5));
		};

	class ChangePasswordUserSettings
	{
		constructor() {
			ko.addObservablesTo(this, {
				changeProcess: false,

				errorDescription: '',
				passwordMismatch: false,
				passwordUpdateError: false,
				passwordUpdateSuccess: false,

				currentPassword: '',
				currentPasswordError: false,
				newPassword: '',
				newPassword2: '',
			});

			this.currentPassword.subscribe(() => this.resetUpdate(true));
			this.newPassword.subscribe(() => this.resetUpdate());
			this.newPassword2.subscribe(() => this.resetUpdate());

			ko.decorateCommands(this, {
				saveNewPasswordCommand: self => !self.changeProcess()
					&& '' !== self.currentPassword()
					&& '' !== self.newPassword()
					&& '' !== self.newPassword2()
			});
		}

		saveNewPasswordCommand() {
			if (this.newPassword() !== this.newPassword2()) {
				this.passwordMismatch(true);
				this.errorDescription(rl.i18n('SETTINGS_CHANGE_PASSWORD/ERROR_PASSWORD_MISMATCH'));
			} else {
				this.reset(true);
				rl.pluginRemoteRequest(
					(...args) => {
						console.dir(...args);
						this.onChangePasswordResponse(...args);
					},
					'ChangePassword',
					{
						'PrevPassword': this.currentPassword(),
						'NewPassword': this.newPassword()
					}
				);
			}
		}

		reset(change) {
			this.changeProcess(change);
			this.resetUpdate();
			this.currentPasswordError(false);
			this.errorDescription('');
		}

		resetUpdate(current) {
			this.passwordUpdateError(false);
			this.passwordUpdateSuccess(false);
			current ? this.currentPasswordError(false) : this.passwordMismatch(false);
		}

		onBuild(dom) {
			let input = dom.querySelector('.new-password'),
				meter = dom.querySelector('.new-password-meter');
			input && meter && input.addEventListener('input',() => meter.value = getPassStrength(input.value));
		}

		onHide() {
			this.reset(false);
			this.currentPassword('');
			this.newPassword('');
			this.newPassword2('');
		}

		onChangePasswordResponse(result, data) {
			this.reset(false);
			if (rl.Enums.StorageResultType.Success === result && data && data.Result) {
				this.currentPassword('');
				this.newPassword('');
				this.newPassword2('');
				this.passwordUpdateSuccess(true);
				rl.hash.set();
				rl.settings.set('AuthAccountHash', data.Result);
			} else {
				this.passwordUpdateError(true);
				this.errorDescription(rl.i18n('NOTIFICATIONS/COULD_NOT_SAVE_NEW_PASSWORD'));
				if (data) {
					if (131 === data.ErrorCode) {
						// Notification.CurrentPasswordIncorrect
						this.currentPasswordError(true);
					}
					if (data.ErrorMessageAdditional) {
						this.errorDescription(data.ErrorMessageAdditional);
					}
				}
			}
		}
	}

	rl.addSettingsViewModel(
		ChangePasswordUserSettings,
		'SettingsChangePassword',
		'GLOBAL/PASSWORD',
		'change-password'
	);

})(window.rl);
