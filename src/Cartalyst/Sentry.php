<?php namespace Cartalyst;
/**
 * Part of the Sentry bundle for Laravel.
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the 3-clause BSD License.
 *
 * This source file is subject to the 3-clause BSD License that is
 * bundled with this package in the LICENSE file.  It is also available at
 * the following URL: http://www.opensource.org/licenses/BSD-3-Clause
 *
 * @package    Sentry
 * @version    1.0
 * @author     Cartalyst LLC
 * @license    BSD License (3-clause)
 * @copyright  (c) 2011 - 2012, Cartalyst LLC
 * @link       http://cartalyst.com
 */

use Cartalyst\Sentry\ProviderInterface;
use Cartalyst\Sentry\UserInterface;
use Cartalyst\Sentry\SessionInterface;
use Cartalyst\Sentry\CookieInterface;

/**
 * Sentry Auth class
 */
class Sentry
{
	/**
	 * The current user
	 *
	 * @var  Cartalyst\Sentry\UserInterface
	 */
	protected $user;

	/**
	 * Provider Interface
	 *
	 * @var  Cartalyst\Sentry\ProviderInterface
	 */
	protected $provider;

	/**
	 * Session provider sentry should use
	 *
	 * @var  Illuminate\Session\Store
	 */
	protected $session;

	/**
	 * Session provider sentry should use
	 *
	 * @var  Illuminate\Session\Store
	 */
	protected $cookie;

	/**
	 * Throttle Enabled
	 *
	 */
	protected $throttle = true;

	/**
	 * Initantiate the Auth class and inject dependencies
	 *
	 * @param   userModel  User Object
	 * @return  object  Auth Instance
	 */
	public function __construct(ProviderInterface $providerInterface, SessionInterface $sessionInterface, CookieInterface $cookieInterface)
	{
		// set dependencies
		$this->provider = $providerInterface;
		$this->session  = $sessionInterface;
		$this->cookie   = $cookieInterface;
	}

	/**
	 * Authenticate a user
	 *
	 * @param   string  login value
	 * @param   string  password value
	 * @param   bool    remember user
	 * @return  bool
	 * @throws  LoginFieldRequiredException,
	 */
	public function authenticate(array $credentials, $remember = false)
	{
		// run logout to clear any current sentry session
		$this->logout();

		try
		{
			// find user by passed credentials
			$user = $this->user()->findByCredentials($credentials);
		}
		catch (Sentry\UserNotFoundException $e)
		{
			// add attempt if throttle is enabled
			if ($this->throttle)
			{
				// get a user object and find the required authentication column
				$login = $this->user()->getLoginColumn();

				if ( ! $this->provider->throttleInterface()->check($credentials[$login]))
				{
					return false;
				}

				$this->provider->throttleInterface()->addAttempt($credentials[$login]);

				unset($login);
			}

			return false;
		}

		if ($this->throttle)
		{
			// before we proceed, check the users' throttle status
			if ( ! $this->provider->throttleInterface()->check($credentials[$user->getLoginColumn()]))
			{
				return false;
			}

			// no exception was thrown for checking, go ahead and clear everything
			$this->provider->throttleInterface()->clearAttempts($credentials[$user->getLoginColumn()]);
		}

		$user->clearResetPassword();

		$this->login($user, $remember, false);

		return true;
	}

	/**
	 * Authenticate a user and remember them
	 *
	 * @param   string  $login
	 * @param   string  $password
	 * @return  bool
	 */
	public function authenticateAndRemember(array $credentials)
	{
		return $this->authenticate($credentials, true);
	}

	/**
	 * Log a user in
	 *
	 * @param   User  $user
	 */
	public function login(UserInterface $user, $remember = false)
	{
		// make sure the user exists
		if ( ! $user->exists)
		{
			throw new UserNotFoundException();
		}

		// check if the user is activated
		if ( ! $user->isActivated())
		{
			throw new UserNotActivatedException();
		}

		$this->user = $user;

		// set sessions
		$this->session->put($this->session->getKey(), $user);

		if ($remember)
		{
			$this->cookie->forever($this->cookie->getKey(), $user);
		}
	}

	/**
	 * Log a user in
	 *
	 * @param   User  $user
	 */
	public function loginAndRemember(userInterface $user)
	{
		return $this->login($user, true);
	}

	/**
	 * Log a user out
	 *
	 * @return  void
	 */
	public function logout()
	{
		$this->user = null;

		$this->session->flush();
		$this->cookie->flush();
	}

	/**
	 * Check to see if the user is logged in
	 *
	 * @return  bool
	 */
	public function check()
	{
		if ($this->user)
		{
			return true;
		}

		// check session
		$this->user = $this->session->get($this->session->getKey(), null);

		// check for cookie
		if ( ! $this->user)
		{
			$this->user = $this->cookie->get($this->cookie->getKey());
		}

		return ! is_null($this->user);
	}

	/**
	 * Returns active authenticated user
	 *
	 * @return Sentry\UserInterface
	 */
	public function activeUser()
	{
		if ( ! $this->check())
		{
			return null;
		}

		$this->user = $this->provider->userInterface()->findById($this->user->id);

		return $this->user;
	}

	/**
	 * Gets a user object
	 *
	 * @param   string  $login
	 * @return  Sentry\UserInterface|null
	 */
	public function user()
	{
		return $this->provider->userInterface();
	}

	/**
	 * Gets a group object
	 */
	public function group()
	{
		return $this->provider->groupInterface();
	}

	/**
	 * Enable throttling
	 *
	 * @param   integer  $limit
	 * @param   integer  $minutes
	 * @throws
	 */
	public function enableThrottle($limit = null, $minutes = null)
	{
		$this->throttle = true;
		! is_null($limit) and $this->provider->throttleInterface()->setAttemptLimit($limit);
		! is_null($minutes) and $this->provider->throttleInterface()->setSuspensionTime($minutes);
	}

	/**
	 * Disables throttling
	 */
	public function disableThrottle()
	{
		$this->throttle = false;
	}

	public function __call($method, $args)
	{
		return call_user_func_array(array($this->provider, $method), $args);
	}
}