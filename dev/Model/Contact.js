import ko from 'ko';

import { ContactPropertyType } from 'Common/Enums';
import { pInt, pString } from 'Common/Utils';
import { emptyContactPic } from 'Common/Links';

import { AbstractModel } from 'Knoin/AbstractModel';

class ContactModel extends AbstractModel {
	constructor() {
		super();

		this.idContact = 0;
		this.display = '';
		this.properties = [];
		this.readOnly = false;

		this.focused = ko.observable(false);
		this.selected = ko.observable(false);
		this.checked = ko.observable(false);
		this.deleted = ko.observable(false);
	}

	/**
	 * @returns {Array|null}
	 */
	getNameAndEmailHelper() {
		let name = '',
			email = '';

		if (Array.isNotEmpty(this.properties)) {
			this.properties.forEach(property => {
				if (property) {
					if (ContactPropertyType.FirstName === property[0]) {
						name = (property[1] + ' ' + name).trim();
					} else if (ContactPropertyType.LastName === property[0]) {
						name = (name + ' ' + property[1]).trim();
					} else if (!email && ContactPropertyType.Email === property[0]) {
						email = property[1];
					}
				}
			});
		}

		return email ? [email, name] : null;
	}

	/**
	 * @static
	 * @param {FetchJsonContact} json
	 * @returns {?ContactModel}
	 */
	static reviveFromJson(json) {
		const contact = super.reviveFromJson(json);
		if (contact) {
			contact.idContact = pInt(json.IdContact);
			contact.display = pString(json.Display);
			contact.readOnly = !!json.ReadOnly;

			if (Array.isNotEmpty(json.Properties)) {
				json.Properties.forEach(property => {
					if (property && property.Type && null != property.Value && null != property.TypeStr) {
						contact.properties.push([pInt(property.Type), pString(property.Value), pString(property.TypeStr)]);
					}
				});
			}
		}
		return contact;
	}

	/**
	 * @returns {string}
	 */
	srcAttr() {
		return emptyContactPic();
	}

	/**
	 * @returns {string}
	 */
	generateUid() {
		return pString(this.idContact);
	}

	/**
	 * @return string
	 */
	lineAsCss() {
		const result = [];
		if (this.deleted()) {
			result.push('deleted');
		}
		if (this.selected()) {
			result.push('selected');
		}
		if (this.checked()) {
			result.push('checked');
		}
		if (this.focused()) {
			result.push('focused');
		}

		return result.join(' ');
	}
}

export { ContactModel, ContactModel as default };
