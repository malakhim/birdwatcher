<?php
/**
 * @package    FrameworkOnFramework
 * @copyright  Copyright (C) 2010 - 2012 Akeeba Ltd. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
// Protect from unauthorized access
defined('_JEXEC') or die();

/**
 * FrameworkOnFramework dispatcher class
 *
 * FrameworkOnFramework is a set of classes whcih extend Joomla! 1.5 and later's
 * MVC framework with features making maintaining complex software much easier,
 * without tedious repetitive copying of the same code over and over again.
 *
 * @package  FrameworkOnFramework.Dispatcher
 * @since    1.0
 */
class FOFDispatcher extends JObject
{

	/** @var array Configuration variables */
	protected $config = array();

	/** @var FOFInput Input variables */
	protected $input = array();

	/** @var string The name of the default view, in case none is specified */
	public $defaultView = 'cpanel';

	// Variables for FOF's transparent user authentication. You can override them
	// in your Dispatcher's __construct() method.

	/** @var int The Time Step for the TOTP used in FOF's transparent user authentication */
	protected $fofAuth_timeStep = 6;

	/** @var string The key for the TOTP, Base32 encoded (watch out; Base32, NOT Base64!) */
	protected $fofAuth_Key = null;

	/** @var array Which formats to be handled by transparent authentication */
	protected $fofAuth_Formats = array('json', 'csv', 'xml', 'raw');

	/**
	 * Should I logout the transparently authenticated user on logout?
	 * Recommended to leave it on in order to avoid crashing the sessions table.
	 *
	 * @var boolean
	 */
	protected $fofAuth_LogoutOnReturn = true;

	/** @var array Which methods to use to fetch authentication credentials and in which order */
	protected $fofAuth_AuthMethods = array(
		/* HTTP Basic Authentication using encrypted information protected
		 * with a TOTP (the username must be "_fof_auth") */
		'HTTPBasicAuth_TOTP',
		/* Encrypted information protected with a TOTP passed in the
		 * _fofauthentication query string parameter */
		'QueryString_TOTP',
		/* HTTP Basic Authentication using a username and password pair in plain text */
		'HTTPBasicAuth_Plaintext',
		/* Plaintext, JSON-encoded username and password pair passed in the
		 * _fofauthentication query string parameter */
		'QueryString_Plaintext',
		/* Plaintext username and password in the _fofauthentication_username
		 * and _fofauthentication_username query string parameters */
		'SplitQueryString_Plaintext',
	);

	/** @var bool Did we successfully and transparently logged in a user? */
	private $_fofAuth_isLoggedIn = false;

	/** @var string The calculated encryption key for the _TOTP methods, used if we have to encrypt the reply */
	private $_fofAuth_CryptoKey = '';

	/**
	 * Get a static (Singleton) instance of a particular Dispatcher
	 *
	 * @param   string  $option  The component name
	 * @param   string  $view    The View name
	 * @param   array   $config  Configuration data
	 *
	 * @staticvar  array  $instances  Holds the array of Dispatchers FOF knows about
	 *
	 * @return  FOFDispatcher
	 */
	public static function &getAnInstance($option = null, $view = null, $config = array())
	{
		static $instances = array();

		$hash = $option . $view;

		if (!array_key_exists($hash, $instances))
		{
			$instances[$hash] = self::getTmpInstance($option, $view, $config);
		}

		return $instances[$hash];
	}

	/**
	 * Gets a temporary instance of a Dispatcher
	 *
	 * @param   string  $option  The component name
	 * @param   string  $view    The View name
	 * @param   array   $config  Configuration data
	 *
	 * @return FOFDispatcher
	 */
	public static function &getTmpInstance($option = null, $view = null, $config = array())
	{
		if (array_key_exists('input', $config))
		{
			if ($config['input'] instanceof FOFInput)
			{
				$input = $config['input'];
			}
			else
			{
				if (!is_array($config['input']))
				{
					$config['input'] = (array) $config['input'];
				}
				$config['input'] = array_merge($_REQUEST, $config['input']);
				$input = new FOFInput($config['input']);
			}
		}
		else
		{
			$input = new FOFInput;
		}

		$config['option'] = !is_null($option) ? $option : $input->getCmd('option', 'com_foobar');
		$config['view'] = !is_null($view) ? $view : $input->getCmd('view', '');
		$input->set('option', $config['option']);
		$input->set('view', $config['view']);
		$config['input'] = $input;

		$className = ucfirst(str_replace('com_', '', $config['option'])) . 'Dispatcher';

		if (!class_exists($className))
		{
			list($isCli, $isAdmin) = self::isCliAdmin();

			if ($isAdmin)
			{
				$basePath = JPATH_ADMINISTRATOR;
			}
			elseif ($isCli)
			{
				$basePath = JPATH_ROOT;
			}
			else
			{
				$basePath = JPATH_SITE;
			}

			$searchPaths = array(
				$basePath . '/components/' . $config['option'],
				$basePath . '/components/' . $config['option'] . '/dispatchers',
				JPATH_ADMINISTRATOR . '/components/' . $config['option'],
				JPATH_ADMINISTRATOR . '/components/' . $config['option'] . '/dispatchers'
			);

			if (array_key_exists('searchpath', $config))
			{
				array_unshift($searchPaths, $config['searchpath']);
			}

			JLoader::import('joomla.filesystem.path');

			$path = JPath::find(
					$searchPaths, 'dispatcher.php'
			);

			if ($path)
			{
				require_once $path;
			}
		}

		if (!class_exists($className))
		{
			$className = 'FOFDispatcher';
		}

		$instance = new $className($config);

		return $instance;
	}

	/**
	 * Public constructor
	 *
	 * @param   array  $config  The configuration variables
	 */
	public function __construct($config = array())
	{
		// Cache the config
		$this->config = $config;

		// Get the input for this MVC triad
		if (array_key_exists('input', $config))
		{
			$this->input = $config['input'];
		}
		else
		{
			$this->input = JRequest::get('default', 3);
		}

		// Get the default values for the component and view names
		$this->component = $this->input->getCmd('option', 'com_foobar');
		$this->view = $this->input->getCmd('view', $this->defaultView);

		if (empty($this->view))
		{
			$this->view = $this->defaultView;
		}
		$this->layout = $this->input->getCmd('layout', null);

		// Overrides from the config
		if (array_key_exists('option', $config))
		{
			$this->component = $config['option'];
		}

		if (array_key_exists('view', $config))
		{
			$this->view = empty($config['view']) ? $this->view : $config['view'];
		}

		if (array_key_exists('layout', $config))
		{
			$this->layout = $config['layout'];
		}

		$this->input->set('option', $this->component);
		$this->input->set('view', $this->view);
		$this->input->set('layout', $this->layout);
	}

	/**
	 * The main code of the Dispatcher. It spawns the necessary controller and
	 * runs it.
	 *
	 * @return  null|Exception
	 */
	public function dispatch()
	{
		// Timezone fix; avoids errors printed out by PHP 5.3.3+
		list($isCli, $isAdmin) = self::isCliAdmin();

		if ($isAdmin)
		{
			// Master access check for the back-end, Joomla! 1.6 style.
			$user = JFactory::getUser();

			if (!$user->authorise('core.manage', $this->input->getCmd('option', 'com_foobar'))
				&& !$user->authorise('core.admin', $this->input->getCmd('option', 'com_foobar')))
			{
				if (version_compare(JVERSION, '3.0', 'ge'))
				{
					throw new Exception(JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
				}
				else
				{
					return JError::raiseError('403', JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));
				}
			}
		}
		elseif (!$isCli)
		{
			// Perform transparent authentication for front-end requests
			$this->transparentAuthentication();
		}

		// Merge English and local translations
		if ($isAdmin)
		{
			$paths = array(JPATH_ROOT, JPATH_ADMINISTRATOR);
		}
		else
		{
			$paths = array(JPATH_ADMINISTRATOR, JPATH_ROOT);
		}

		$jlang = JFactory::getLanguage();
		$jlang->load($this->component, $paths[0], 'en-GB', true);
		$jlang->load($this->component, $paths[0], null, true);
		$jlang->load($this->component, $paths[1], 'en-GB', true);
		$jlang->load($this->component, $paths[1], null, true);

		$canDispatch = true;
		if($isCli)
		{
			$canDispatch = $canDispatch && $this->onBeforeDispatchCLI();
		}

		$canDispatch = $canDispatch && $this->onBeforeDispatch();

		if (!$canDispatch)
		{
			JResponse::setHeader('Status', '403 Forbidden', true);

			if (version_compare(JVERSION, '3.0', 'ge'))
			{
				throw new Exception(JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
			}
			else
			{
				return JError::raiseError('403', JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));
			}
		}

		// Get and execute the controller
		$option = $this->input->getCmd('option', 'com_foobar');
		$view = $this->input->getCmd('view', $this->defaultView);
		$task = $this->input->getCmd('task', '');

		if (empty($task))
		{
			$task = $this->getTask($view);
		}

		// Pluralise/sungularise the view name for typical tasks

		if (in_array($task, array('edit', 'add', 'read')))
		{
			$view = FOFInflector::singularize($view);
		}
		elseif (in_array($task, array('browse')))
		{
			$view = FOFInflector::pluralize($view);
		}

		$this->input->set('view', $view);
		$this->input->set('task', $task);

		$config = $this->config;
		$config['input'] = $this->input;

		$controller = FOFController::getTmpInstance($option, $view, $config);
		$status = $controller->execute($task);

		if ($status === false)
		{
			JResponse::setHeader('Status', '403 Forbidden', true);

			if (version_compare(JVERSION, '3.0', 'ge'))
			{
				throw new Exception(JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
			}
			else
			{
				return JError::raiseError('403', JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));
			}
		}

		if (!$this->onAfterDispatch())
		{
			JResponse::setHeader('Status', '403 Forbidden', true);

			if (version_compare(JVERSION, '3.0', 'ge'))
			{
				throw new Exception(JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
			}
			else
			{
				return JError::raiseError('403', JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));
			}
		}

		$format = $this->input->get('format', 'html', 'cmd');
		$format = empty($format) ? 'html' : $format;

		if ($format == 'html')
		{
			// In HTML views perform a redirection
			$controller->redirect();
		}
		else
		{
			// In non-HTML views just exit the application with the proper HTTP headers
			if ($controller->hasRedirect())
			{
				$headers = JResponse::sendHeaders();
				jexit();
			}
		}
	}

	/**
	 * Tries to guess the controller task to execute based on the view name and
	 * the HTTP request method.
	 *
	 * @param   string  $view  The name of the view
	 *
	 * @return  string  The best guess of the task to execute
	 */
	protected function getTask($view)
	{
		// Get a default task based on plural/singular view
		$task = FOFInflector::isPlural($view) ? 'browse' : 'edit';

		// Get a potential ID, we might need it later
		$id = $this->input->get('id', null);

		if ($id == 0)
		{
			$ids = $this->input->get('ids', array(), 'array');

			if (!empty($ids))
			{
				$id = array_shift($ids);
			}
		}

		// Check the request method

		if (!array_key_exists('REQUEST_METHOD', $_SERVER))
		{
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		$requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);

		switch ($requestMethod)
		{
			case 'POST':
			case 'PUT':
				if ($id != 0)
					$task = 'save';
				break;

			case 'DELETE':
				if ($id != 0)
					$task = 'delete';
				break;

			case 'GET':
			default:
				list($isCli, $isAdmin) = self::isCliAdmin();

				// If it's an edit without an ID or ID=0, it's really an add
				if (($task == 'edit') && ($id == 0))
				{
					$task = 'add';
				}
				// If it's an edit in the frontend, it's really a read
				elseif (($task == 'edit') && !$isCli && !$isAdmin)
				{
					$task = 'read';
				}
				break;
		}

		return $task;
	}

	/**
	 * Executes right before the dispatcher tries to instantiate and run the
	 * controller.
	 *
	 * @return  boolean  Return false to abort
	 */
	public function onBeforeDispatch()
	{
		return true;
	}

	/**
	 * Sets up some environment variables, so we can work as usually on CLI, too.
	 *
	 * @return  boolean  Return false to abort
	 */
	public function onBeforeDispatchCLI()
	{
		JLoader::import('joomla.environment.uri');
		JLoader::import('joomla.application.component.helper');

		// Trick to create a valid url used by JURI
		$this->_originalPhpScript = '';

		// We have no Application Helper (there is no Application!), so I have to define these constants manually
		$option = $this->input->get('option');
		if($option)
		{
			if(!defined('JPATH_COMPONENT'))
			{
				define('JPATH_COMPONENT', JPATH_BASE . '/components/' . $option);
			}

			if(!defined('JPATH_COMPONENT_SITE'))
			{
				define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/' . $option);
			}

			if(!defined('JPATH_COMPONENT_ADMINISTRATOR'))
			{
				define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/' . $option);
			}
		}

		return true;
	}

	/**
	 * Executes right after the dispatcher runs the controller.
	 *
	 * @return  boolean  Return false to abort
	 */
	public function onAfterDispatch()
	{
		// If we have to log out the user, please do so now

		if ($this->fofAuth_LogoutOnReturn && $this->_fofAuth_isLoggedIn)
		{
			JLoader::import('joomla.user.authentication');
			$app = JFactory::getApplication();
			$options = array('remember'	 => false);
			$parameters = array('username'	 => JFactory::getUser()->username);
			$results = $app->triggerEvent('onLogoutUser', array($parameters, $options));
		}

		return true;
	}

	/**
	 * Transparently authenticates a user
	 *
	 * @return  void
	 */
	public function transparentAuthentication()
	{
		// Only run when there is no logged in user

		if (!JFactory::getUser()->guest)
		{
			return;
		}

		// @todo Check the format
		$format = $this->input->getCmd('format', 'html');

		if (!in_array($format, $this->fofAuth_Formats))
		{
			return;
		}

		foreach ($this->fofAuth_AuthMethods as $method)
		{
			// If we're already logged in, don't bother

			if ($this->_fofAuth_isLoggedIn)
				continue;

			// This will hold our authentication data array (username, password)
			$authInfo = null;

			switch ($method)
			{
				case 'HTTPBasicAuth_TOTP':

					if (empty($this->fofAuth_Key))
					{
						continue;
					}

					if (!isset($_SERVER['PHP_AUTH_USER']))
					{
						continue;
					}

					if (!isset($_SERVER['PHP_AUTH_PW']))
					{
						continue;
					}

					if ($_SERVER['PHP_AUTH_USER'] != '_fof_auth')
					{
						continue;
					}

					$encryptedData = $_SERVER['PHP_AUTH_PW'];

					$authInfo = $this->_decryptWithTOTP($encryptedData);
					break;

				case 'QueryString_TOTP':
					$encryptedData = $this->input->get('_fofauthentication', '');

					if (empty($encryptedData))
					{
						continue;
					}

					$authInfo = $this->_decryptWithTOTP($encryptedData);
					break;

				case 'HTTPBasicAuth_Plaintext':
					if (!isset($_SERVER['PHP_AUTH_USER']))
					{
						continue;
					}

					if (!isset($_SERVER['PHP_AUTH_PW']))
					{
						continue;
					}

					$authInfo = array(
						'username'	 => $_SERVER['PHP_AUTH_USER'],
						'password'	 => $_SERVER['PHP_AUTH_PW']
					);
					break;

				case 'QueryString_Plaintext':
					$jsonencoded = $this->input->get('_fofauthentication', '');

					if (empty($jsonencoded))
					{
						continue;
					}

					$authInfo = json_decode($jsonencoded, true);

					if (!is_array($authInfo))
					{
						$authInfo = null;
					}
					elseif (!array_key_exists('username', $authInfo) || !array_key_exists('password', $authInfo))
					{
						$authInfo = null;
					}
					break;

				case 'SplitQueryString_Plaintext':
					$authInfo = array(
						'username'	 => $this->input->get('_fofauthentication_username', ''),
						'password'	 => $this->input->get('_fofauthentication_password', ''),
					);

					if (empty($authInfo['username']))
					{
						$authInfo = null;
					}

					break;

				default:
					continue;

					break;
			}

			// No point trying unless we have a username and password
			if (!is_array($authInfo))
			{
				continue;
			}

			JLoader::import('joomla.user.authentication');
			$app = JFactory::getApplication();
			$options = array('remember'		 => false);
			$authenticate = JAuthentication::getInstance();
			$response = $authenticate->authenticate($authInfo, $options);

			if ($response->status == JAUTHENTICATE_STATUS_SUCCESS)
			{
				JPluginHelper::importPlugin('user');
				$results = $app->triggerEvent('onLoginUser', array((array) $response, $options));

				JLoader::import('joomla.user.helper');
				$userid = JUserHelper::getUserId($response->username);
				$user = JFactory::getUser($userid);

				$session = JFactory::getSession();
				$session->set('user', $user);

				// $results = $app->triggerEvent('onLogoutUser', array($parameters, $options));

				$this->_fofAuth_isLoggedIn = true;
			}
		}
	}

	/**
	 * Decrypts a transparent authentication message using a TOTP
	 *
	 * @param   string  $encryptedData  The encrypted data
	 *
	 * @return  array  The decrypted data
	 */
	private function _decryptWithTOTP($encryptedData)
	{
		if (empty($this->fofAuth_Key))
		{
			$this->_fofAuth_CryptoKey = null;

			return null;
		}

		$totp = new FOFEncryptTotp($this->fofAuth_timeStep);
		$period = $totp->getPeriod();
		$period--;

		for ($i = 0; $i <= 2; $i++)
		{
			$time = ($period + $i) * $this->fofAuth_timeStep;
			$otp = $totp->getCode($this->fofAuth_Key, $time);
			$this->_fofAuth_CryptoKey = hash('sha256', $this->fofAuth_Key . $otp);

			$aes = new FOFEncryptAes($this->_fofAuth_CryptoKey);
			$ret = $aes->decryptString($encryptedData);
			$ret = rtrim($ret, "\000");

			$ret = json_decode($ret, true);

			if (!is_array($ret))
			{
				continue;
			}

			if (!array_key_exists('username', $ret))
			{
				continue;
			}

			if (!array_key_exists('password', $ret))
			{
				continue;
			}

			// Successful decryption!
			return $ret;
		}

		// Obviously if we're here we could not decrypt anything. Bail out.
		$this->_fofAuth_CryptoKey = null;

		return null;
	}

	/**
	 * Creates a decryption key for use with the TOTP decryption method
	 *
	 * @param   integer  $time  The timestamp used for TOTP calculation, leave empty to use current timestamp
	 *
	 * @return  string  THe encryption key
	 */
	private function _createDecryptionKey($time = null)
	{
		$totp = new FOFEncryptTotp($this->fofAuth_timeStep);
		$otp = $totp->getCode($this->fofAuth_Key, $time);

		$key = hash('sha256', $this->fofAuth_Key . $otp);

		return $key;
	}

	/**
	 * Main function to detect if we're running in a CLI environment and we're admin
	 *
	 * @return  array  isCLI and isAdmin. It's not an associtive array, so we can use list.
	 */
	public static function isCliAdmin()
	{
		static $isCLI = null;
		static $isAdmin = null;

		if (is_null($isCLI) && is_null($isAdmin))
		{
			try
			{
				if (is_null(JFactory::$application))
				{
					$isCLI = true;
				}
				else
				{
					$isCLI = JFactory::getApplication() instanceof JException;
				}
			}
			catch (Exception $e)
			{
				$isCLI = true;
			}

			if ($isCLI)
			{
				$isAdmin = false;
			}
			else
			{
				$isAdmin = !JFactory::$application ? false : JFactory::getApplication()->isAdmin();
			}
		}

		return array($isCLI, $isAdmin);
	}

}
