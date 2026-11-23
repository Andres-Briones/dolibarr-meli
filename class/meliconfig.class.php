<?php
/* Copyright (C) 2026 Briones Andres
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/meliconfig.class.php
 * \ingroup meli
 * \brief   Settings of the module: addresses of Mercado Libre, application credentials.
 */

/**
 * Settings of the module
 */
class MeliConfig
{
	const SITE = 'MLA';

	/**
	 * Tell whether the module talks to a test double instead of Mercado Libre.
	 *
	 * @return	bool
	 */
	public static function isFake()
	{
		return (getDolGlobalString('MELI_API_URL') !== '' || getDolGlobalString('MELI_AUTH_URL') !== '');
	}

	/**
	 * Base address of the API.
	 *
	 * @return	string	Without trailing slash
	 */
	public static function apiUrl()
	{
		return rtrim(getDolGlobalString('MELI_API_URL', 'https://api.mercadolibre.com'), '/');
	}

	/**
	 * Base address of the authorisation pages (Argentina).
	 *
	 * @return	string	Without trailing slash
	 */
	public static function authUrl()
	{
		return rtrim(getDolGlobalString('MELI_AUTH_URL', 'https://auth.mercadolibre.com.ar'), '/');
	}

	/**
	 * Id of the application at Mercado Libre.
	 *
	 * @return	string
	 */
	public static function appId()
	{
		return trim(getDolGlobalString('MELI_APP_ID'));
	}

	/**
	 * Secret of the application. Stored encrypted.
	 *
	 * @return	string	'' if not configured
	 */
	public static function appSecret()
	{
		$stored = getDolGlobalString('MELI_APP_SECRET');

		return $stored === '' ? '' : (string) dolDecrypt($stored);
	}

	/**
	 * Address Mercado Libre sends the seller back to after the authorisation. It must be the one
	 * registered with the application, character for character.
	 *
	 * @return	string
	 */
	public static function redirectUrl()
	{
		return getDolGlobalString('MELI_REDIRECT_URL', dol_buildpath('/meli/public/oauth.php', 2));
	}

	/**
	 * Address of the notifications callback, to register with the application.
	 *
	 * @return	string
	 */
	public static function webhookUrl()
	{
		/** @var Conf $conf */
		global $conf;

		return dol_buildpath('/meli/public/webhook.php', 2).'?entity='.((int) $conf->entity);
	}
}
