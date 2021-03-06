<?php

namespace RainLoop;

class ServiceActions
{
	/**
	 * @var \MailSo\Base\Http
	 */
	protected $oHttp;

	/**
	 * @var \RainLoop\Actions
	 */
	protected $oActions;

	/**
	 * @var array
	 */
	protected $aPaths;

	/**
	 * @var string
	 */
	protected $sQuery;

	public function __construct(\MailSo\Base\Http $oHttp, Actions $oActions)
	{
		$this->oHttp = $oHttp;
		$this->oActions = $oActions;
		$this->aPaths = array();
		$this->sQuery = '';
	}

	public function Logger() : \MailSo\Log\Logger
	{
		return $this->oActions->Logger();
	}

	public function Plugins() : Plugins\Manager
	{
		return $this->oActions->Plugins();
	}

	public function Config() : Config\Application
	{
		return $this->oActions->Config();
	}

	public function Cacher() : \MailSo\Cache\CacheClient
	{
		return $this->oActions->Cacher();
	}

	public function StorageProvider() : Providers\Storage
	{
		return $this->oActions->StorageProvider();
	}

	public function SettingsProvider() : Providers\Settings
	{
		return $this->oActions->SettingsProvider();
	}

	public function SetPaths(array $aPaths) : self
	{
		$this->aPaths = $aPaths;
		return $this;
	}

	public function SetQuery(string $sQuery) : self
	{
		$this->sQuery = $sQuery;
		return $this;
	}

	public function ServiceJson() : string
	{
		\ob_start();

		$aResponseItem = null;
		$oException = null;

		$sAction = $this->oHttp->GetPost('Action');
		if (empty($sAction) && $this->oHttp->IsGet() && !empty($this->aPaths[2]))
		{
			$sAction = $this->aPaths[2];
		}

		$this->oActions->SetIsJson(true);

		try
		{
			if ($this->oHttp->IsPost() &&
				$this->Config()->Get('security', 'csrf_protection', false) &&
				$this->oHttp->GetPost('XToken', '') !== Utils::GetCsrfToken())
			{
				throw new Exceptions\ClientException(Notifications::InvalidToken);
			}
			else if (!empty($sAction))
			{
				if (0 === stripos($sAction, 'Admin') && 'AdminLogin' !== $sAction && 'AdminLogout' !== $sAction) {
					$this->oActions->IsAdminLoggined();
				}

				$sMethodName = 'Do'.$sAction;

				$this->Logger()->Write('Action: '.$sMethodName, \MailSo\Log\Enumerations\Type::NOTE, 'JSON');

				$aPost = $this->oHttp->GetPostAsArray();
				if ($aPost)
				{
					$this->oActions->SetActionParams($aPost, $sMethodName);
					foreach ($aPost as $key => $value) {
						if (false !== \stripos($key, 'Password')) {
							$aPost[$key] = '*******';
						}
					}
/*
					switch ($sMethodName)
					{
						case 'DoLogin':
						case 'DoAdminLogin':
						case 'DoAccountAdd':
							$this->Logger()->AddSecret($this->oActions->GetActionParam('Password', ''));
							break;
					}
*/
					$this->Logger()->Write(\MailSo\Base\Utils::Php2js($aPost, $this->Logger()),
						\MailSo\Log\Enumerations\Type::INFO, 'POST', true);
				}
				else if (3 < \count($this->aPaths) && $this->oHttp->IsGet())
				{
					$this->oActions->SetActionParams(array(
						'RawKey' => empty($this->aPaths[3]) ? '' : $this->aPaths[3]
					), $sMethodName);
				}

				if (\method_exists($this->oActions, $sMethodName) &&
					\is_callable(array($this->oActions, $sMethodName)))
				{
					$this->Plugins()->RunHook('json.action-pre-call', array($sAction));
					$aResponseItem = \call_user_func(array($this->oActions, $sMethodName));
					$this->Plugins()->RunHook('json.action-post-call', array($sAction, &$aResponseItem));
				}
				else if ($this->Plugins()->HasAdditionalJson($sMethodName))
				{
					$this->Plugins()->RunHook('json.action-pre-call', array($sAction));
					$aResponseItem = $this->Plugins()->RunAdditionalJson($sMethodName);
					$this->Plugins()->RunHook('json.action-post-call', array($sAction, &$aResponseItem));
				}
			}

			if (!\is_array($aResponseItem))
			{
				throw new Exceptions\ClientException(Notifications::UnknownError);
			}
		}
		catch (\Throwable $oException)
		{
			error_log($oException->getMessage());
			if ($e = $oException->getPrevious()) {
				error_log("\t{$e->getMessage()} @ {$e->getFile()}#{$e->getLine()}");
			}

			$aResponseItem = $this->oActions->ExceptionResponse(
				empty($sAction) ? 'Unknown' : $sAction, $oException);

			if (\is_array($aResponseItem) && $oException instanceof Exceptions\ClientException)
			{
				if ('Folders' === $sAction)
				{
					$aResponseItem['ClearAuth'] = true;
				}

				if ($oException->getLogoutOnException())
				{
					$aResponseItem['Logout'] = true;
					if ($oException->getAdditionalMessage())
					{
						$this->oActions->SetSpecLogoutCustomMgsWithDeletion($oException->getAdditionalMessage());
					}
				}
			}
		}

		if (\is_array($aResponseItem))
		{
			$aResponseItem['Time'] = (int) ((\microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);

			$sUpdateToken = $this->oActions->GetUpdateAuthToken();
			if ($sUpdateToken)
			{
				$aResponseItem['UpdateToken'] = $sUpdateToken;
			}
		}

		$this->Plugins()->RunHook('filter.json-response', array($sAction, &$aResponseItem));

		\header('Content-Type: application/json; charset=utf-8');

		$sResult = \MailSo\Base\Utils::Php2js($aResponseItem, $this->Logger());

		$sObResult = \ob_get_clean();

		if ($this->Logger()->IsEnabled())
		{
			if (0 < \strlen($sObResult))
			{
				$this->Logger()->Write($sObResult, \MailSo\Log\Enumerations\Type::ERROR, 'OB-DATA');
			}

			if ($oException)
			{
				$this->Logger()->WriteException($oException, \MailSo\Log\Enumerations\Type::ERROR);
			}

			$iLimit = (int) $this->Config()->Get('labs', 'log_ajax_response_write_limit', 0);
			$this->Logger()->Write(0 < $iLimit && $iLimit < \strlen($sResult)
					? \substr($sResult, 0, $iLimit).'...' : $sResult, \MailSo\Log\Enumerations\Type::INFO, 'JSON');
		}

		return $sResult;
	}

	public function ServiceAppend() : string
	{
		\ob_start();
		$bResponse = false;
		$oException = null;
		try
		{
			if (\method_exists($this->oActions, 'Append') &&
				\is_callable(array($this->oActions, 'Append')))
			{
				$this->oActions->SetActionParams($this->oHttp->GetPostAsArray(), 'Append');
				$bResponse = \call_user_func(array($this->oActions, 'Append'));
			}
		}
		catch (\Throwable $oException)
		{
			$bResponse = false;
		}

		\header('Content-Type: text/plain; charset=utf-8');
		$sResult = true === $bResponse ? '1' : '0';

		$sObResult = \ob_get_clean();
		if (0 < \strlen($sObResult))
		{
			$this->Logger()->Write($sObResult, \MailSo\Log\Enumerations\Type::ERROR, 'OB-DATA');
		}

		if ($oException)
		{
			$this->Logger()->WriteException($oException, \MailSo\Log\Enumerations\Type::ERROR);
		}

		$this->Logger()->Write($sResult, \MailSo\Log\Enumerations\Type::INFO, 'APPEND');

		return $sResult;
	}

	private function privateUpload(string $sAction, int $iSizeLimit = 0) : string
	{
		$oConfig = $this->Config();

		\ob_start();
		$aResponseItem = null;
		try
		{
			$aFile = null;
			$sInputName = 'uploader';
			$iError = Enumerations\UploadError::UNKNOWN;
			$iSizeLimit = (0 < $iSizeLimit ? $iSizeLimit : ((int) $oConfig->Get('webmail', 'attachment_size_limit', 0))) * 1024 * 1024;

			$iError = UPLOAD_ERR_OK;
			$_FILES = isset($_FILES) ? $_FILES : null;
			if (isset($_FILES, $_FILES[$sInputName], $_FILES[$sInputName]['name'], $_FILES[$sInputName]['tmp_name'], $_FILES[$sInputName]['size']))
			{
				$iError = (isset($_FILES[$sInputName]['error'])) ? (int) $_FILES[$sInputName]['error'] : UPLOAD_ERR_OK;

				if (UPLOAD_ERR_OK === $iError && 0 < $iSizeLimit && $iSizeLimit < (int) $_FILES[$sInputName]['size'])
				{
					$iError = Enumerations\UploadError::CONFIG_SIZE;
				}

				if (UPLOAD_ERR_OK === $iError)
				{
					$aFile = $_FILES[$sInputName];
				}
			}
			else if (!isset($_FILES) || !is_array($_FILES) || 0 === count($_FILES))
			{
				$iError = UPLOAD_ERR_INI_SIZE;
			}
			else
			{
				$iError = Enumerations\UploadError::EMPTY_FILES_DATA;
			}

			if (\method_exists($this->oActions, $sAction) &&
				\is_callable(array($this->oActions, $sAction)))
			{
				$aActionParams = $this->oHttp->GetQueryAsArray();

				$aActionParams['File'] = $aFile;
				$aActionParams['Error'] = $iError;

				$this->oActions->SetActionParams($aActionParams, $sAction);

				$aResponseItem = \call_user_func(array($this->oActions, $sAction));
			}

			if (!is_array($aResponseItem))
			{
				throw new Exceptions\ClientException(Notifications::UnknownError);
			}
		}
		catch (\Throwable $oException)
		{
			$aResponseItem = $this->oActions->ExceptionResponse($sAction, $oException);
		}

		\header('Content-Type: application/json; charset=utf-8');

		$this->Plugins()->RunHook('filter.upload-response', array(&$aResponseItem));
		$sResult = \MailSo\Base\Utils::Php2js($aResponseItem, $this->Logger());

		$sObResult = \ob_get_clean();
		if (0 < \strlen($sObResult))
		{
			$this->Logger()->Write($sObResult, \MailSo\Log\Enumerations\Type::ERROR, 'OB-DATA');
		}

		$this->Logger()->Write($sResult, \MailSo\Log\Enumerations\Type::INFO, 'UPLOAD');

		return $sResult;
	}

	public function ServiceUpload() : string
	{
		return $this->privateUpload('Upload');
	}

	public function ServiceUploadContacts() : string
	{
		return $this->privateUpload('UploadContacts', 5);
	}

	public function ServiceUploadBackground() : string
	{
		return $this->privateUpload('UploadBackground', 1);
	}

	public function ServiceProxyExternal() : string
	{
		$bResult = false;
		$sData = empty($this->aPaths[1]) ? '' : $this->aPaths[1];
		if (!empty($sData) && $this->oActions->Config()->Get('labs', 'use_local_proxy_for_external_images', false))
		{
			$this->oActions->verifyCacheByKey($sData);

			$aData = Utils::DecodeKeyValuesQ($sData);
			if (\is_array($aData) && !empty($aData['Token']) && !empty($aData['Url']) && $aData['Token'] === Utils::GetConnectionToken())
			{
				$iCode = 404;
				$sContentType = '';
				$mResult = $this->oHttp->GetUrlAsString($aData['Url'], 'SnappyMail External Proxy', $sContentType, $iCode);

				if (false !== $mResult && 200 === $iCode &&
					\in_array($sContentType, array('image/png', 'image/jpeg', 'image/jpg', 'image/bmp', 'image/gif')))
				{
					$bResult = true;

					$this->oActions->cacheByKey($sData);

					\header('Content-Type: '.$sContentType);
					echo $mResult;
				}
			}
		}

		if (!$bResult)
		{
			$this->oHttp->StatusHeader(404);
		}

		return '';
	}

	public function ServiceRaw() : string
	{
		$sResult = '';
		$sRawError = '';
		$sAction = empty($this->aPaths[2]) ? '' : $this->aPaths[2];
		$oException = null;

		try
		{
			$sRawError = 'Invalid action';
			if (0 !== \strlen($sAction))
			{
				$sMethodName = 'Raw'.$sAction;
				if (\method_exists($this->oActions, $sMethodName))
				{
					\header('X-Raw-Action: '.$sMethodName, true);
					\header('Content-Security-Policy: script-src \'none\'; child-src \'none\'', true);

					$sRawError = '';
					$this->oActions->SetActionParams(array(
						'RawKey' => empty($this->aPaths[3]) ? '' : $this->aPaths[3],
						'Params' => $this->aPaths
					), $sMethodName);

					if (!\call_user_func(array($this->oActions, $sMethodName)))
					{
						$sRawError = 'False result';
					}
					else
					{
						$sRawError = '';
					}
				}
				else
				{
					$sRawError = 'Unknown action "'.$sAction.'"';
				}
			}
			else
			{
				$sRawError = 'Empty action';
			}
		}
		catch (Exceptions\ClientException $oException)
		{
			$sRawError = 'Exception as result';
			switch ($oException->getCode())
			{
				case Notifications::AuthError:
					$sRawError = 'Authentication failed';
					break;
			}
		}
		catch (\Throwable $oException)
		{
			$sRawError = 'Exception as result';
		}

		if (0 < \strlen($sRawError))
		{
			$this->oActions->Logger()->Write($sRawError, \MailSo\Log\Enumerations\Type::ERROR);
			$this->oActions->Logger()->WriteDump($this->aPaths, \MailSo\Log\Enumerations\Type::ERROR, 'PATHS');
		}

		if ($oException)
		{
			$this->Logger()->WriteException($oException, \MailSo\Log\Enumerations\Type::ERROR, 'RAW');
		}

		return $sResult;
	}

	public function ServiceLang() : string
	{
//		sleep(2);
		$sResult = '';
		\header('Content-Type: application/javascript; charset=utf-8');

		if (!empty($this->aPaths[3]))
		{
			$bAdmin = 'Admin' === (isset($this->aPaths[2]) ? (string) $this->aPaths[2] : 'App');
			$sLanguage = $this->oActions->ValidateLanguage($this->aPaths[3], '', $bAdmin);

			$bCacheEnabled = $this->Config()->Get('labs', 'cache_system_data', true);
			if (!empty($sLanguage) && $bCacheEnabled)
			{
				$this->oActions->verifyCacheByKey($this->sQuery);
			}

			$sCacheFileName = '';
			if ($bCacheEnabled)
			{
				$sCacheFileName = KeyPathHelper::LangCache(
					$sLanguage, $bAdmin, $this->oActions->Plugins()->Hash());

				$sResult = $this->Cacher()->Get($sCacheFileName);
			}

			if (0 === \strlen($sResult))
			{
				$sResult = $this->compileLanguage($sLanguage, $bAdmin);
				if ($bCacheEnabled && 0 < \strlen($sCacheFileName))
				{
					$this->Cacher()->Set($sCacheFileName, $sResult);
				}
			}

			if ($bCacheEnabled)
			{
				$this->oActions->cacheByKey($this->sQuery);
			}
		}

		return $sResult;
	}

	public function ServiceTemplates() : string
	{
		$sResult = '';
		\header('Content-Type: application/javascript; charset=utf-8');

		$bCacheEnabled = $this->Config()->Get('labs', 'cache_system_data', true);
		if ($bCacheEnabled)
		{
			$this->oActions->verifyCacheByKey($this->sQuery);
		}

		$bAdmin = false !== \strpos($this->sQuery, 'Admin');

		$sCacheFileName = '';
		if ($bCacheEnabled)
		{
			$sCacheFileName = KeyPathHelper::TemplatesCache($bAdmin, $this->oActions->Plugins()->Hash());
			$sResult = $this->Cacher()->Get($sCacheFileName);
		}

		if (0 === \strlen($sResult))
		{
			$sResult = $this->compileTemplates($bAdmin);
			if ($bCacheEnabled && 0 < \strlen($sCacheFileName))
			{
				$this->Cacher()->Set($sCacheFileName, $sResult);
			}
		}

		if ($bCacheEnabled)
		{
			$this->oActions->cacheByKey($this->sQuery);
		}

		return $sResult;
	}

	public function ServicePlugins() : string
	{
		$sResult = '';
		$bAdmin = !empty($this->aPaths[2]) && 'Admin' === $this->aPaths[2];

		\header('Content-Type: application/javascript; charset=utf-8');

		$bCacheEnabled = $this->Config()->Get('labs', 'cache_system_data', true);
		if ($bCacheEnabled)
		{
			$this->oActions->verifyCacheByKey($this->sQuery);
		}

		$sCacheFileName = '';
		if ($bCacheEnabled)
		{
			$sCacheFileName = KeyPathHelper::PluginsJsCache($this->oActions->Plugins()->Hash());
			$sResult = $this->Cacher()->Get($sCacheFileName);
		}

		if (0 === strlen($sResult))
		{
			$sResult = $this->Plugins()->CompileJs($bAdmin);
			if ($bCacheEnabled && 0 < \strlen($sCacheFileName))
			{
				$this->Cacher()->Set($sCacheFileName, $sResult);
			}
		}

		if ($bCacheEnabled)
		{
			$this->oActions->cacheByKey($this->sQuery);
		}

		return $sResult;
	}

	public function ServiceCss() : string
	{
		$sResult = '';

		$bAdmin = !empty($this->aPaths[2]) && 'Admin' === $this->aPaths[2];
		$bJson = !empty($this->aPaths[9]) && 'Json' === $this->aPaths[9];

		if ($bJson)
		{
			\header('Content-Type: application/json; charset=utf-8');
		}
		else
		{
			\header('Content-Type: text/css; charset=utf-8');
		}

		$sTheme = '';
		if (!empty($this->aPaths[4]))
		{
			$sTheme = $this->oActions->ValidateTheme($this->aPaths[4]);
			$sRealTheme = $sTheme;

			$bCustomTheme = '@custom' === \substr($sTheme, -7);
			if ($bCustomTheme)
			{
				$sRealTheme = \substr($sTheme, 0, -7);
			}

			$bCacheEnabled = $this->Config()->Get('labs', 'cache_system_data', true);
			if ($bCacheEnabled)
			{
				$this->oActions->verifyCacheByKey($this->sQuery);
			}

			$sCacheFileName = '';
			if ($bCacheEnabled)
			{
				$sCacheFileName = KeyPathHelper::CssCache($sTheme, $this->oActions->Plugins()->Hash());
				$sResult = $this->Cacher()->Get($sCacheFileName);
			}

			if (0 === \strlen($sResult))
			{
				try
				{
					$oLess = new \LessPHP\lessc();
					$oLess->setFormatter('compressed');

					$aResult = array();

					$sThemeFile = ($bCustomTheme ? APP_INDEX_ROOT_PATH : APP_VERSION_ROOT_PATH).'themes/'.$sRealTheme.'/styles.less';

					if (\is_file($sThemeFile))
					{
						$aResult[] = '@base: "'
							. ($bCustomTheme ? Utils::WebPath() : Utils::WebVersionPath())
							. 'themes/'.$sRealTheme.'/";';

						$aResult[] = \file_get_contents($sThemeFile);
					}

					$aResult[] = $this->Plugins()->CompileCss($bAdmin);

					$sResult = $oLess->compile(\implode("\n", $aResult));

					if ($bCacheEnabled)
					{
						if (0 < \strlen($sCacheFileName))
						{
							$this->Cacher()->Set($sCacheFileName, $sResult);
						}
					}
				}
				catch (\Throwable $oException)
				{
					$this->Logger()->WriteException($oException, \MailSo\Log\Enumerations\Type::ERROR, 'LESS');
				}
			}

			if ($bCacheEnabled)
			{
				$this->oActions->cacheByKey($this->sQuery);
			}
		}

		return $bJson ? \MailSo\Base\Utils::Php2js(array($sTheme, $sResult), $this->Logger()) : $sResult;
	}

	public function ServiceAppData() : string
	{
		return $this->localAppData(false);
	}

	public function ServiceAdminAppData() : string
	{
		return $this->localAppData(true);
	}

	public function ServiceNoScript() : string
	{
		return $this->localError($this->oActions->StaticI18N('STATIC/NO_SCRIPT_TITLE'), $this->oActions->StaticI18N('STATIC/NO_SCRIPT_DESC'));
	}

	public function ServiceNoCookie() : string
	{
		return $this->localError($this->oActions->StaticI18N('STATIC/NO_COOKIE_TITLE'), $this->oActions->StaticI18N('STATIC/NO_COOKIE_DESC'));
	}

	public function ServiceBadBrowser() : string
	{
		$sTitle = $this->oActions->StaticI18N('STATIC/BAD_BROWSER_TITLE');
		$sDesc = \nl2br($this->oActions->StaticI18N('STATIC/BAD_BROWSER_DESC'));

		\header('Content-Type: text/html; charset=utf-8');
		return \strtr(\file_get_contents(APP_VERSION_ROOT_PATH.'app/templates/BadBrowser.html'), array(
			'{{BaseWebStaticPath}}' => Utils::WebStaticPath(),
			'{{ErrorTitle}}' => $sTitle,
			'{{ErrorHeader}}' => $sTitle,
			'{{ErrorDesc}}' => $sDesc
		));
	}

	public function ServiceMailto() : string
	{
		$this->oHttp->ServerNoCache();

		$sTo = \trim($this->oHttp->GetQuery('to', ''));
		if (!empty($sTo) && \preg_match('/^mailto:/i', $sTo))
		{
			$oAccount = $this->oActions->GetAccountFromSignMeToken();
			if ($oAccount)
			{
				$this->oActions->SetMailtoRequest($sTo);
			}
		}

		$this->oActions->Location('./');
		return '';
	}

	public function ServicePing() : string
	{
		$this->oHttp->ServerNoCache();

		\header('Content-Type: text/plain; charset=utf-8');
		$this->oActions->Logger()->Write('Pong', \MailSo\Log\Enumerations\Type::INFO, 'PING');
		return 'Pong';
	}

	public function ServiceSso() : string
	{
		$this->oHttp->ServerNoCache();

		$oException = null;
		$oAccount = null;
		$bLogout = true;

		$sSsoHash = $this->oHttp->GetRequest('hash', '');
		if (!empty($sSsoHash))
		{
			$mData = null;

			$sSsoSubData = $this->Cacher()->Get(KeyPathHelper::SsoCacherKey($sSsoHash));
			if (!empty($sSsoSubData))
			{
				$mData = Utils::DecodeKeyValuesQ($sSsoSubData);
				$this->Cacher()->Delete(KeyPathHelper::SsoCacherKey($sSsoHash));

				if (\is_array($mData) && !empty($mData['Email']) && isset($mData['Password'], $mData['Time']) &&
					(0 === $mData['Time'] || \time() - 10 < $mData['Time']))
				{
					$sEmail = \trim($mData['Email']);
					$sPassword = $mData['Password'];

					$aAdditionalOptions = isset($mData['AdditionalOptions']) && \is_array($mData['AdditionalOptions']) &&
						0 < \count($mData['AdditionalOptions']) ? $mData['AdditionalOptions'] : null;

					try
					{
						$oAccount = $this->oActions->LoginProcess($sEmail, $sPassword);

						if ($oAccount instanceof Model\Account && $aAdditionalOptions)
						{
							$bNeedToSettings = false;

							$oSettings = $this->SettingsProvider()->Load($oAccount);
							if ($oSettings)
							{
								$sLanguage = isset($aAdditionalOptions['Language']) ?
									$aAdditionalOptions['Language'] : '';

								if ($sLanguage)
								{
									$sLanguage = $this->oActions->ValidateLanguage($sLanguage);
									if ($sLanguage !== $oSettings->GetConf('Language', ''))
									{
										$bNeedToSettings = true;
										$oSettings->SetConf('Language', $sLanguage);
									}
								}
							}

							if ($bNeedToSettings)
							{
								$this->SettingsProvider()->Save($oAccount, $oSettings);
							}
						}

						$this->oActions->AuthToken($oAccount);

						$bLogout = !($oAccount instanceof Model\Account);
					}
					catch (\Throwable $oException)
					{
						$this->oActions->Logger()->WriteException($oException);
					}
				}
			}
		}

		if ($bLogout)
		{
			$this->oActions->SetAuthLogoutToken();
		}

		$this->oActions->Location('./');
		return '';
	}

	public function ServiceRemoteAutoLogin() : string
	{
		$oException = null;
		$oAccount = null;
		$bLogout = true;

		$sEmail = $this->oHttp->GetEnv('REMOTE_USER', '');
		$sPassword = $this->oHttp->GetEnv('REMOTE_PASSWORD', '');

		if (0 < \strlen($sEmail) && 0 < \strlen(\trim($sPassword)))
		{
			try
			{
				$oAccount = $this->oActions->LoginProcess($sEmail, $sPassword);
				$this->oActions->AuthToken($oAccount);
				$bLogout = !($oAccount instanceof Model\Account);
			}
			catch (\Throwable $oException)
			{
				$this->oActions->Logger()->WriteException($oException);
			}
		}

		if ($bLogout)
		{
			$this->oActions->SetAuthLogoutToken();
		}

		$this->oActions->Location('./');
		return '';
	}

	public function ServiceExternalLogin() : string
	{
		$this->oHttp->ServerNoCache();

		$oException = null;
		$oAccount = null;
		$bLogout = true;

		switch (\strtolower($this->oHttp->GetRequest('Output', 'Redirect')))
		{
			case 'json':

				\header('Content-Type: application/json; charset=utf-8');

				$aResult = array(
					'Action' => 'ExternalLogin',
					'Result' => $oAccount instanceof Model\Account ? true : false,
					'ErrorCode' => 0
				);

				if (!$aResult['Result'])
				{
					if ($oException instanceof Exceptions\ClientException)
					{
						$aResult['ErrorCode'] = $oException->getCode();
					}
					else
					{
						$aResult['ErrorCode'] = Notifications::AuthError;
					}
				}

				return \MailSo\Base\Utils::Php2js($aResult, $this->Logger());

			case 'redirect':
			default:
				$this->oActions->Location('./');
				break;
		}

		return '';
	}

	private function changeAction()
	{
		$this->oHttp->ServerNoCache();

		$oAccount = $this->oActions->GetAccount();

		if ($oAccount && $this->oActions->GetCapa(false, Enumerations\Capa::ADDITIONAL_ACCOUNTS, $oAccount))
		{
			$oAccountToLogin = null;
			$sEmail = empty($this->aPaths[2]) ? '' : \urldecode(\trim($this->aPaths[2]));
			if (!empty($sEmail))
			{
				$sEmail = \MailSo\Base\Utils::IdnToAscii($sEmail);

				$aAccounts = $this->oActions->GetAccounts($oAccount);
				if (isset($aAccounts[$sEmail]))
				{
					$oAccountToLogin = $this->oActions->GetAccountFromCustomToken($aAccounts[$sEmail], false, false);
				}
			}

			if ($oAccountToLogin)
			{
				$this->oActions->AuthToken($oAccountToLogin);
			}
		}
	}

	public function ServiceChange() : string
	{
		$this->changeAction();
		$this->oActions->Location('./');
		return '';
	}

	/**
	 * @return mixed
	 */
	public function ErrorTemplates(string $sTitle, string $sDesc, bool $bShowBackLink = true)
	{
		return strtr(file_get_contents(APP_VERSION_ROOT_PATH.'app/templates/Error.html'), array(
			'{{BaseWebStaticPath}}' => Utils::WebStaticPath(),
			'{{ErrorTitle}}' => $sTitle,
			'{{ErrorHeader}}' => $sTitle,
			'{{ErrorDesc}}' => $sDesc,
			'{{BackLinkVisibilityStyle}}' => $bShowBackLink ? 'display:inline-block' : 'display:none',
			'{{BackLink}}' => $this->oActions->StaticI18N('STATIC/BACK_LINK'),
			'{{BackHref}}' => './'
		));
	}

	private function localError(string $sTitle, string $sDesc) : string
	{
		header('Content-Type: text/html; charset=utf-8');
		return $this->ErrorTemplates($sTitle, \nl2br($sDesc));
	}

	private function localAppData(bool $bAdmin = false) : string
	{
		\header('Content-Type: application/javascript; charset=utf-8');
		$this->oHttp->ServerNoCache();

		$sAuthAccountHash = '';
		if (!$bAdmin && 0 === \strlen($this->oActions->GetSpecAuthLogoutTokenWithDeletion()))
		{
			$sAuthAccountHash = $this->oActions->GetSpecAuthTokenWithDeletion();
			if (empty($sAuthAccountHash))
			{
				$sAuthAccountHash = $this->oActions->GetSpecAuthToken();
			}

			if (empty($sAuthAccountHash))
			{
				$oAccount = $this->oActions->GetAccountFromSignMeToken();
				if ($oAccount)
				{
					try
					{
						$this->oActions->CheckMailConnection($oAccount);

						$this->oActions->AuthToken($oAccount);

						$sAuthAccountHash = $this->oActions->GetSpecAuthToken();
					}
					catch (\Throwable $oException)
					{
						$oException = null;
						$this->oActions->ClearSignMeData($oAccount);
					}
				}
			}

			$this->oActions->SetSpecAuthToken($sAuthAccountHash);
		}

		$sResult = 'rl.initData('
			.\json_encode($this->oActions->AppData($bAdmin, $sAuthAccountHash))
			.');';

		$this->Logger()->Write($sResult, \MailSo\Log\Enumerations\Type::INFO, 'APPDATA');

		return $sResult;
	}

	public function compileTemplates(bool $bAdmin = false, bool $bJsOutput = true) : string
	{
		$aTemplates = array();

		Utils::CompileTemplates($aTemplates, APP_VERSION_ROOT_PATH.'app/templates/Views/Components', 'Component');
		Utils::CompileTemplates($aTemplates, APP_VERSION_ROOT_PATH.'app/templates/Views/'.($bAdmin ? 'Admin' : 'User'));
		Utils::CompileTemplates($aTemplates, APP_VERSION_ROOT_PATH.'app/templates/Views/Common');

		$this->oActions->Plugins()->CompileTemplate($aTemplates, $bAdmin);

		$sHtml = '';
		foreach ($aTemplates as $sName => $sFile)
		{
			$sName = \preg_replace('/[^a-zA-Z0-9]/', '', $sName);
			$sHtml .= '<template id="'.$sName.'">'.
				$this->oActions->ProcessTemplate($sName, \file_get_contents($sFile)).'</template>';
		}

		unset($aTemplates);

		return $bJsOutput ? 'rl.TEMPLATES='.\MailSo\Base\Utils::Php2js($sHtml, $this->Logger()).';' : $sHtml;
	}

	private function compileLanguage(string $sLanguage, bool $bAdmin = false) : string
	{
		$aResultLang = array();

		Utils::ReadAndAddLang(APP_VERSION_ROOT_PATH.'app/localization/langs.yml', $aResultLang);
		Utils::ReadAndAddLang(APP_VERSION_ROOT_PATH.'app/localization/'.
			($bAdmin ? 'admin' : 'webmail').'/_source.en.yml', $aResultLang);
		Utils::ReadAndAddLang(APP_VERSION_ROOT_PATH.'app/localization/'.
			($bAdmin ? 'admin' : 'webmail').'/'.$sLanguage.'.yml', $aResultLang);

		$this->Plugins()->ReadLang($sLanguage, $aResultLang);

		$sResult = '';
		$aLangKeys = \array_keys($aResultLang);
		foreach ($aLangKeys as $sKey)
		{
			$sString = isset($aResultLang[$sKey]) ? $aResultLang[$sKey] : $sKey;
			if (\is_array($sString))
			{
				$sString = \implode("\n", $sString);
			}

			$sResult .= '"'.\str_replace('"', '\\"', \str_replace('\\', '\\\\', $sKey)).'":'
				.'"'.\str_replace(array("\r", "\n", "\t"), array('\r', '\n', '\t'),
					\str_replace('"', '\\"', \str_replace('\\', '\\\\', $sString))).'",';
		}
		$sResult = $sResult ? '{'.\substr($sResult, 0, -1).'}' : 'null';

		$sLanguage = strtr($sLanguage, '_', '-');

		$sTimeFormat = '';
		$options = [$sLanguage, \substr($sLanguage, 0, 2), 'en'];
		foreach ($options as $lang) {
			$sFileName = APP_VERSION_ROOT_PATH.'app/localization/relativetimeformat/'.$lang.'.js';
			if (\is_file($sFileName)) {
				$sTimeFormat = \preg_replace('/^\\s+/', '', \file_get_contents($sFileName));
				break;
			}
		}

		return "document.documentElement.lang = '{$sLanguage}';\nwindow.rainloopI18N={$sResult};\nDate.defineRelativeTimeFormat({$sTimeFormat});";
	}
}
