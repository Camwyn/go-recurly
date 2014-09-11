<?php

class GO_Recurly
{
	public $config = NULL;
	public $admin = NULL;
	public $meta_key_prefix = 'go-recurly_';
	public $version = '1';

	private $user_profile = NULL;
	private $recurly_client = NULL;
	private $registered_pages = array();

	/**
	 * constructor
	 *
	 * @param $config array of configuration settings (optional)
	 */
	public function __construct( $config = NULL )
	{
		$this->config = apply_filters( 'go_config', $config, 'go-recurly' );

		if ( empty( $this->config ) )
		{
			return;
		}

		// add our user cap at priority 11, after go-subscription's
		add_filter( 'user_has_cap', array( $this, 'user_has_cap' ), 10, 3 );

		if ( ! is_admin() )
		{
			add_shortcode( 'go_recurly_subscription_form', array( $this, 'subscription_form' ) );

			add_action( 'init', array( $this, 'init' ) );

			$this->detect_coupon();
		}//end if

		// on any other blog, we do not want/need the rest of this plugin's functionality
		if ( 'pro' != go_config()->get_property_slug() && 'accounts' != go_config()->get_property_slug() )
		{
			return;
		}

		if ( is_admin() )
		{
			$this->admin();
		}
		else
		{
			add_action( 'go_user_profile_email_updated', array( $this, 'go_user_profile_email_updated' ), 10, 2 );
		}

		add_filter( 'go_remote_identity_nav', array( $this, 'go_remote_identity_nav' ), 12, 2 );
		add_filter( 'go_user_profile_screens', array( $this, 'go_user_profile_screens' ) );
	}//end __construct

	/**
	 * hooked to WordPress init
	 */
	public function init()
	{
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] )
		{
			$this->handle_post();
		}

		// doing this here rather than on the wp_enqueue_scripts hook so
		// that it is done before pre_get_posts
		$this->wp_enqueue_scripts();
	}//end init

	/**
	 * Keeping form post handling segregated (or should we just merge this
	 * into init?)
	 */
	public function handle_post()
	{
		if ( isset( $_POST['recurly_token'] ) )
		{
			// handle the post response from recurly after step 2 signup form
			$this->thankyou();
		}
	}//end handle_post

	/**
	 * register and enqueue scripts and styles
	 */
	public function wp_enqueue_scripts()
	{
		$script_config = apply_filters( 'go_config', array( 'version' => 1 ), 'go-script-version' );

		wp_register_script(
			'recurly-js',
			plugins_url( 'js/external/recurly-js/recurly.min.js', __FILE__ ),
			array( 'jquery' ),
			$script_config['version'],
			TRUE
		);

		wp_register_script(
			'go-recurly-config',
			plugins_url( 'js/go-recurly-config.js', __FILE__ ),
			array(
				'jquery',
				'recurly-js',
			),
			$script_config['version'],
			TRUE
		);

		wp_register_script(
			'go-recurly',
			plugins_url( 'js/go-recurly.js', __FILE__ ),
			array( 'go-recurly-config' ),
			$script_config['version'],
			TRUE
		);

		wp_register_script(
			'go-recurly-behavior',
			plugins_url( 'js/go-recurly-behavior.js', __FILE__ ),
			array( 'go-recurly' ),
			$script_config['version'],
			TRUE
		);

		wp_register_style( 'go-recurly', plugins_url( 'css/go-recurly.css', __FILE__ ), array(), $script_config['version'] );
		wp_register_style( 'recurly-css', plugins_url( 'js/external/recurly-js/themes/default/recurly.css', __FILE__ ), array(), $script_config['version'] );

		wp_enqueue_script( 'recurly-js' );
		wp_enqueue_script( 'go-recurly-config' );
		wp_enqueue_script( 'go-recurly' );
		wp_enqueue_script( 'go-recurly-behavior' );

		wp_enqueue_style( 'recurly-css' );
		wp_enqueue_style( 'go-recurly' );

		// check if we have an email-less user, which can exist if the user
		// logged in with a social network account
		$user_has_email = 0;
		$user = go_subscriptions()->get_user();
		if ( $user && isset( $user['email'] ) && ! empty( $user['email'] ) )
		{
			$user_has_email = 1;
		}
		wp_localize_script(
			'go-recurly-config',
			'go_recurly_settings',
			array(
				'subdomain' => $this->config['recurly_subdomain'],
				'user_has_email' => $user_has_email,
			)
		);
		wp_localize_script( 'go-recurly-behavior', 'go_recurly_ajax', array( 'url' => site_url( '/wp-admin/admin-ajax.php' ) ) );
	}//end wp_enqueue_scripts

	/**
	 * retrieves an admin singleton
	 */
	public function admin()
	{
		if ( ! $this->admin )
		{
			require_once __DIR__ . '/class-go-recurly-admin.php';

			$this->admin = new GO_Recurly_Admin( $this );
		}

		return $this->admin;
	}//end admin

	/**
	 * hooked to user_has_cap filter
	 *
	 * @param $all_caps array of capabilities they have (to be filtered)
	 * @param $unused_meta_caps array of the required capabilities they need to have for a successful current_user_can
	 * @param $args array [0] Requested capability
	 *                    [1] User ID
 	 *                    [2] Associated object ID
	 */
	public function user_has_cap( $all_caps, $unused_meta_caps, $args )
	{
		list( $cap, $user_id ) = $args;

		$subscription = go_subscriptions()->get_subscription_meta( $user_id );

		// did_trial indicates that the user had a trial account at one time (not necessarily still in their trial)
		if ( isset( $subscription['sub_trial_started_at'] ) )
		{
			$all_caps['did_trial'] = TRUE;
		}

		// did_subscription indicates that the user at one time successfully paid for a subscription.
		if ( isset( $subscription['sub_did_subscription'] ) )
		{
			$all_caps['did_subscription'] = TRUE;
		}

		// sub_state_active indicates that they have an active subscription
		// sub_state_expired indicates that they have an expired subscription
		if ( isset( $subscription['sub_state'] ) )
		{
			// theoretically could be: "active", "canceled", "expired", "future", "in_trial", "live", or "past_due".
			// since this is set via push, "active" and "expired" seem to be the states that come through consistently
			$all_caps[ 'sub_state_' . $subscription['sub_state'] ] = TRUE;

			// in case this is not being run from the main site, add the subscriber role if their account is active
			if ( 'active' == $subscription['sub_state'] )
			{
				$all_caps['subscriber'] = TRUE;
			}
		}//end if

		// has_subscription_data should be set for any user who went through the recurly sign up
		$account_code = $this->get_account_code( $user_id );

		if ( $account_code )
		{
			$all_caps['has_subscription_data'] = TRUE;
		}

		// login_with_key is for go-softlogins
		// @todo: should this include 'guest' as well?
		if ( empty( $all_caps['has_subscription_data'] ) )
		{
			if ( ! empty( $all_caps['guest-prospect'] ) || ! empty( $all_caps['guest'] ) )
			{
				$all_caps['login_with_key'] = TRUE;
			}
		}//end if

		// nothing else has set this as a subscriber, let's dig deeper
		// @TODO: this doesn't really belong here, but it's the best place we have for now (10/24/2013)
		if ( ! isset( $all_caps['subscriber'] ) )
		{
			// by getting from wp_{config['accounts_blog_id']}_capabilities, we get them for the primary blog instead of whichever is running this
			// @TODO: this makes some assumptions about the table prefix that aren't really square, i.e., we can't do that for primary blog id = 1; that would be "wp_capabilities"
			$capabilities = get_user_meta( $user_id, 'wp_' . $this->config['accounts_blog_id'] . '_capabilities' );

			foreach ( $capabilities as $capability )
			{
				if ( isset( $capability['subscriber-lifetime'] ) )
				{
					$all_caps['subscriber'] = TRUE;
				}
			}//end foreach
		}//end if

		return $all_caps;
	}//END user_has_cap

	/**
	 * detects if a coupon is set in the URL and sets a coupon cookie
	 */
	public function detect_coupon()
	{
		if ( ! isset( $_GET['coupon'] ) || ! $_GET['coupon'] )
		{
			return;
		}

		setcookie( 'go_recurly_coupon', $_GET['coupon'] );
	}//end detect_coupon

	/**
	 * builds a subscription nav section to be inserted into a user's
	 * remote identity payload
	 *
	 * @param array $nav the menu structure to be filtered
	 * @param WP_User $user a WP_User object
	 */
	public function go_remote_identity_nav( $nav, $user )
	{
		$page_data = $this->page_data();

		unset( $page_data['subscription']['children']['list'] );
		unset( $page_data['subscription']['children']['cancel'] );
		unset( $page_data['subscription']['children']['invoice'] );

		foreach ( $page_data as $page )
		{
			$url = home_url( "/members/{$user->ID}/{$page['slug']}/", 'https' );

			$nav[ $page['slug'] ] = array(
				'title' => $page['name'],
				'url' => $url,
			);

			if ( isset( $page['children'] ) )
			{
				foreach ( $page['children'] as $child )
				{
					$url = isset( $child['url'] ) ? $child['url'] : home_url( "/members/{$user->ID}/{$page['slug']}/{$child['slug']}/", 'https' );

					$nav[ $page['slug'] ]['nav'][ $child['slug'] ] = array(
						'title' => $child['name'],
						'url' => $url,
					);
				}//end foreach
			}//end if
		}//end foreach

		return $nav;
	}//end go_remote_identity_nav

	/**
	 * add subscriptions pages to the menus
	 */
	public function go_user_profile_screens( $screens )
	{
		$pages = $this->page_data();
		return array_merge( $screens, $pages );
	}//end go_user_profile_screens

	/**
	 * Subscription page data
	 */
	public function page_data()
	{
		// we don't show the subscriptions menu if they have no subscription data
		// @TODO: it might be good to give a sign up for free trial CTA in place of where this menu would have been
		if ( ! $this->registered_pages && $this->user_profile() )
		{
			$this->registered_pages = array(
				'subscription' => array(
					'name' => 'Subscriptions',
					'slug' => 'subscription',
					'position' => 100,
					'show_for_displayed_user' => false,
					'screen_function' => array( $this->user_profile(), 'subscriptions' ),
					'default_subnav_slug' => 'list',
					'children' => array(
						'list' => array(
							'name' => 'Details',
							'position' => 10,
							'slug' => 'list',
							'screen_function' => array( $this->user_profile(), 'subscriptions' ),
						),
						'billing' => array(
							'name' => 'Billing',
							'position' => 15,
							'slug' => 'billing',
							'screen_function' => array( $this->user_profile(), 'billing' ),
						),
						'history' => array(
							'name' => 'Payment history',
							'position' => 20,
							'slug' => 'history',
							'screen_function' => array( $this->user_profile(), 'history' ),
						),
						'cancel' => array(
							'name' => 'Cancel subscription',
							'position' => 30,
							'slug' => 'cancel',
							'screen_function' => array( $this->user_profile(), 'cancel' ),
							'hidden' => TRUE,
						),
						'contact_support' => array(
							'name' => 'Contact support',
							'position' => 40,
							'slug' => 'contact',
							'url' => get_site_url( 4, '/contact/', 'http' ),
						),
						'invoice' => array(
							'name' => 'Invoice',
							'position' => 90,
							'slug' => 'invoice',
							'screen_function' => array( $this->user_profile(), 'invoice' ),
							'hidden' => TRUE,
						),
					),
				),
			);
			
			// set up some of the values that are built from base values
			foreach ( $this->registered_pages as &$parent )
			{
				$parent['item_css_id'] = $parent['slug'];

				foreach ( $parent['children'] as &$child )
				{
					$child['parent_slug'] = $parent['slug'];
					$child['item_css_id'] = "{$parent['slug']}-{$child['slug']}";
				}//end foreach
			}//end foreach
		}//end if

		return $this->registered_pages;
	}//end page_data

	/**
	 * after go-user-profile email settings are changed, sync new info over
	 * to Recurly
	 */
	public function go_user_profile_email_updated( $user, $new_email )
	{
		// make sure the email is set appropriately in the object
		// (this skirts around caching issues by cheating)
		$user->user_email = $new_email;

		return $this->update_email( $user );
	}//end go_user_profile_email_updated

	/**
	 * retrieves a user's account code from user meta
	 *
	 * @param $user_id int WordPress user ID
	 */
	public function get_account_code( $user_id )
	{
		return get_user_meta( $user_id, $this->meta_key_prefix . 'account_code', TRUE );
	}//end get_account_code

	/**
	 * Singleton for the GO_Recurly_User_Profile class
	 */
	public function user_profile()
	{
		if ( ! $this->user_profile )
		{
			// trigger the initialization of the GO_Recurly_User_Profile object
			// and its relevant actions ONLY if the user has ever had subscription info
			$user = wp_get_current_user();

			if ( $user->has_cap( 'has_subscription_data' ) )
			{
				require_once __DIR__ . '/class-go-recurly-user-profile.php';
				$this->user_profile = new GO_Recurly_User_Profile( $this );
			}
		}//end if

		return $this->user_profile;
	}//end user_profile

	/**
	 * @param int $user_id id of user to cancel the subscription for
	 * @param mixed $subscription can be 'all', a subscription UUID, or a subscription object
	 * @param $terminate_refund can be FALSE, "none", "all", or "partial"
	 * @return boolean returns FALSE if all is well, or an error message if there was problem
	 */
	public function cancel_subscription( $user_id, $subscription = 'all', $terminate_refund = FALSE )
	{
		// @todo: check status of subscription before cancelling, perhaps also catch the recurly errors...
		$this->recurly_client();

		$account_code = $this->get_account_code( $user_id );

		if ( empty( $account_code ) )
		{
			return FALSE; // nothing to cancel
		}

		$subscriptions = array();

		if ( 'all' == $subscription )
		{
			$subscriptions = Recurly_SubscriptionList::getForAccount( $account_code );
		}
		elseif ( is_object( $subscription ) )
		{
			$subscriptions[] = $subscription;
		}
		else
		{
			$subscriptions[] = Recurly_Subscription::get( $subscription );
		}

		$return = FALSE;

		foreach ( $subscriptions as $sub )
		{
			try
			{
				if ( $terminate_refund )
				{
					switch ( $terminate_refund )
					{
						case 'full':
							$sub->terminateAndRefund();
							break;
						case 'partial':
							$sub->terminateAndPartialRefund();
							break;
						case 'none':
							$sub->terminateWithoutRefund();
							break;
					}//end switch
				}//end if
				else
				{
					$sub->cancel();
				}
			}//end try
			catch( Exception $e )
			{
				$return = $e->getMessage();
			}
		}//end foreach

		do_action( 'go_recurly_subscriptions_cancel', $user_id, $subscriptions, $terminate_refund, $return );

		return $return;
	}//end cancel_subscription

	/**
	 * update a user email address in Recurly
	 *
	 * @param WP_User $user a user object
	 */
	public function update_email( $user )
	{
		$recurly = $this->recurly_client();

		$account_code = $this->get_account_code( $user->ID );

		if ( empty( $account_code ) )
		{
			apply_filters( 'go_slog', 'go-recurly', 'update_email(): no recurly account code', $user_id );
			return; // nothing to update
		}

		try
		{
			$r_account = Recurly_Account::get( $account_code, $recurly );

			// bail early if we didn't get a proper Account object
			if ( ! ( $r_account instanceof Recurly_Account ) )
			{
				return;
			}

			if ( $r_account->email != $user->user_email )
			{
				$r_account->email = $user->user_email;

				$r_account->update();
			}
		}//end try
		catch( Exception $e )
		{
			// if a recurly account does not exist for the user, don't do anything
		}
	}//end update_email

	/**
	 * Get the second form in the 2-step process, unless they are already
	 * logged in, then fetches the 1st step
	 *
	 * @param array $user a user array whose 'obj' element is a WP_User object
	 * @param array $atts attributes needed by the form
	 * @return mixed FALSE if we're not ready for the 2nd step form yet
	 */
	public function subscription_form( $user, $atts )
	{
		// test cc #'s:
		// 4111-1111-1111-1111  -  will succeed
		// 4000-0000-0000-0002  - will be declined
		$sc_atts = shortcode_atts(
			array(
				'plan_code' => $this->config['default_recurly_plan_code'],
				'terms_url' => 'http://gigaom.com/terms-of-service/',
				'thankyou_path' => $this->config['thankyou_path'], // xxx
			),
			$atts
		);

		$user = go_subscriptions()->get_user();

		if (
			isset( $_GET['plan_code'] ) &&
			$_GET['plan_code'] != $this->config['default_recurly_plan_code']
		)
		{
			// if a plan code is passed in that doesn't match the default plan code, use that
			$sc_atts['plan_code'] = trim( $_GET['plan_code'] );
		}
		else
		{
			// otherwise, use the default (and adjust based on previous trial)
			if ( $user && $user['obj']->has_cap( 'did_trial' ) )
			{
				// this strips off any trailing "-7daytrial" in plan code
				list( $sc_atts['plan_code'] ) = explode( '-', $sc_atts['plan_code'] );
			}
		}//end else

		if ( ! $user && isset( $_GET['email'] ) && is_email( $_GET['email'] ) )
		{
			// we will load the object so that we can see if they already have a recurly account code
			$user = array(
				'email' => $_GET['email'],
				'obj' => get_user_by( 'email', $_GET['email'] ),
			);
		}//end if

		$this->recurly_client();

		$account_code = $this->get_or_create_account_code( $user['obj'] );

		if ( empty( $account_code ) || empty( $user['email'] ) )
		{
			// the user is loading the 2nd step form prematurely. return the
			// form for step 1
			return go_subscriptions()->signup_form( $atts );
		}

		$signature = $this->sign_subscription( $account_code, $sc_atts['plan_code'] );
		$coupon    = $_COOKIE['go_subscription_coupon'] ?: '';

		wp_localize_script( 'go-recurly', 'go_recurly', array(
			'account' => array(
				'firstName'   => $user['first_name'],
				'lastName'    => $user['last_name'],
				'email'       => $user['email'],
				'companyName' => $user['company'],
			),
			'billing' => array(
				'firstName' => $user['first_name'],
				'lastName'  => $user['last_name'],
			),
			'subscription' => array(
				'couponCode' => $coupon,
			),
		) );

		$args = array(
			'signature' => $signature,
			'url'       => wp_validate_redirect( $sc_atts['thankyou_path'], $this->config['thankyou_path'] ),
			'plan_code' => $sc_atts['plan_code'],
			'terms_url' => $sc_atts['terms_url'],
		);

		return $this->get_template_part( 'subscription-form.php', $args );
	}//end subscription_form

	/**
	 * Get the template part in an output buffer and return it
	 *
	 * @param string $template_name
	 * @param array $template_variables used in included templates
	 *
	 * @todo Rudimentary part/child theme file_exists() checks
	 */
	public function get_template_part( $template_name, $template_variables = array() )
	{
		ob_start();
		include( __DIR__ . '/templates/' . $template_name );
		return ob_get_clean();
	}//end get_template_part

	/**
	 * retrieves a user by account code
	 */
	public function get_user_by_account_code( $account_code )
	{
		if ( ! $account_code )
		{
			return FALSE;
		}

		$args = array(
			'fields' => 'all_with_meta',
			'meta_key' => $this->meta_key_prefix . 'account_code',
			'meta_value' => sanitize_key( $account_code ),
		);

		if ( ! ( $query = new WP_User_Query( $args ) ) )
		{
			return FALSE;
		}

		$user = array_shift( $query->results );

		return $user;
	}//end get_user_by_account_code

	/**
	 * return all the meta that is set by this plugin in an array
	 * @param int $user_id WordPress user id
	 * @return array of meta values
	 */
	public function get_user_meta( $user_id )
	{
		// Note: we are not locally caching this as get_user_meta() should be doing it for us,
		//   and this plugin might accidentally invalidate the cache, not worth detecting that
		$meta_vals = array();

		$profile_data = apply_filters( 'go_user_profile_get_meta', array(), $user_id );

		$meta_vals['company'] = isset( $profile_data['company'] ) ? $profile_data['company'] : '';
		$meta_vals['title'] = isset( $profile_data['title'] ) ? $profile_data['title'] : '';

		$meta_vals['account_code'] = $this->get_account_code( $user_id );
		$meta_vals['subscription'] = go_subscriptions()->get_subscription_meta( $user_id );

		$meta_vals['converted_meta'] = go_subscriptions()->get_converted_meta( $user_id );

		return $meta_vals;
	}//end get_user_meta

	/**
	 * get the account_code if it exists, otherwise, create it for the user
	 */
	public function get_or_create_account_code( $user )
	{
		if ( ! ( $user instanceof WP_User ) || $user->ID == 0 )
		{
			return FALSE;
		}

		$account_code = $this->get_account_code( $user->ID );

		if ( ! $account_code )
		{
			$account_code = md5( uniqid() );

			update_user_meta( $user->ID, $this->meta_key_prefix . 'account_code', $account_code );
		}

		return $account_code;
	}//end get_or_create_account_code

	/**
	 * initializes and instantiates Recurly_Client and Recurly_js
	 */
	public function recurly_client( $client_object = null )
	{
		// allow for mock client objects
		if ( $client_object )
		{
			$this->recurly_client = $client_object;
		}//end if

		if ( ! $this->recurly_client )
		{
			require_once( __DIR__ . '/external/recurly-client/lib/recurly.php' );

			// Required for the API
			Recurly_Client::$apiKey = $this->config['recurly_api_key'];

			// Optional for Recurly.js:
			Recurly_js::$privateKey = $this->config['recurly_js_api_key'];

			$this->recurly_client = new Recurly_Client;
		}//end if

		return $this->recurly_client;
	} // end recurly_client

	/**
	 * Wrapper function to access the Recurly API and get the user account details
	 */
	public function recurly_get_account( $user_id )
	{
		$client = $this->recurly_client();

		try
		{
			$account_code = $this->get_account_code( $user_id );

			if ( empty( $account_code ) )
			{
				apply_filters( 'go_slog', 'go-recurly', 'expected Recurly account code, but found none', $user_id );
				return FALSE;
			}// end if

			$account = Recurly_Account::get( $account_code, $client );
		}
		catch( Recurly_NotFoundError $e )
		{
			$account = FALSE;
		}

		return $account;
	}//end recurly_get_account

	/**
	 * Translate a Recurly API notification to a WP_User object:
	 *
	 *   - if $notification->account->account_code is present
	 *     - try to look up user by matching the account_code with the
	 *       go_recurly_account_code user metadata value
	 *     - if not found then "throw a hissy fit!"
	 *
	 *   - if account code is not present, then try to look up the
	 *     user by $notification->account->email.
	 *     - if not found then try to create a new user for that email address
	 *       and "throw a hissy fit" if we cannot create that user
	 *     - upon creating the new user, the user's go_recurly_account_code
	 *       should be generated and sync'ed to recurly.
	 *
	 * @param $notification object Recurly notification object (parsed from XML)
	 * @return WP_User object which is either newly created or existing.
	 * @return FALSE if we failed to create a new user or if we cannot
	 *  look up the user by recurly account code.
	 */
	public function recurly_get_user( $notification )
	{
		if (
			isset( $notification->account->account_code ) &&
			! empty( $notification->account->account_code )
		)
		{
			// $notification->account and its children are SimpleXMLElement
			// objects, which must be casted to string to get to their
			// text contents.
			$account_code = (string) $notification->account->account_code;

			if ( $user = $this->get_user_by_account_code( $account_code ) )
			{
				return $user;
			}
			apply_filters( 'go_slog', 'go-recurly', 'failed to find a user with recurly account code: ' . $account_code, $notification );
		}//END if
		elseif (
			isset( $notification->account->email ) &&
			! empty( $notification->account->email )
		)
		{
			$email = (string) $notification->account->email;

			// this shouldn't happen very often at all. but if it does,
			// we'll try to look up the user by $notification->account->email
			$user = get_user_by( 'email', $email );

			if ( $user )
			{
				return $user;
			}

			// else try to create a new user by the email
			$new_user = array(
				'email' => $email,
				'first_name' => (string) $notification->account->first_name,
				'last_name' => (string) $notification->account->last_name,
				'company' => (string) $notification->account->company_name,
			);

			$user_id = $this->create_guest_user( $new_user );

			if ( is_wp_error( $user_id ) )
			{
				apply_filters( 'go_slog', 'go-recurly', 'failed to create a new guest user with email: ' . $email, array( $notification, $user_id ) );
				return FALSE;
			}

			// make sure the user has a recurly account code and that it's
			// sync'ed to recurly
			$user = get_user_by( 'id', $user_id );

			$recurly_account_code = $this->get_or_create_account_code( $user );

			$ret = $this->admin()->recurly_sync( $user );

			if ( ! $ret || is_wp_error( $ret ) )
			{
				apply_filters( 'go_slog', 'go-recurly', 'failed to sync new user recurly account code to recurly!!!', array( $notification, $ret ) );
				return FALSE;
			}

			return $user;
		}//END elseif

		return FALSE;
	}//end recurly_get_user

	/**
	 * Sign the billing form (presented within User Profile forms)
	 *
	 * @param $account_code string Recurly account code
	 * @return string signature
	 */
	public function sign_billing( $account_code )
	{
		$this->recurly_client();

		$signature = Recurly_js::sign(
			array(
				'account' => array(
					'account_code' => $account_code,
				),
			)
		);

		return $signature;
	}//end sign_billing

	/**
	 * Sign the subscription form
	 *
	 * @param $account_code string Recurly account code
	 * @param $plan_code string Recurly subscription plan code
	 * @return string signature
	 */
	public function sign_subscription( $account_code, $plan_code )
	{
		$this->recurly_client();

		$signature = Recurly_js::sign(
			array(
				'account' => array(
					'account_code' => $account_code,
				),
				'subscription' => array(
					'plan_code' => $plan_code,
				),
			)
		);

		return $signature;
	}//end sign_subscription

	public function get_account_from_token()
	{
		$this->recurly_client();

		// note: the object we get back here varies in really important ways
		// from what we get in the Recurly push
		$result = Recurly_js::fetch( $_POST['recurly_token'] );

		if ( 'Recurly_BillingInfo' == get_class( $result ) )
		{
			// this was a billing info update
			return FALSE;
		}

		return $result->account->get();
	}//END get_account_from_token

	/**
	 * Lookup potential coupon code for account
	 *
	 * @param $account_code the Recurly account code
	 */
	public function coupon_code( $account_code )
	{
		$this->recurly_client();

		try
		{
			$coupon_redemption = Recurly_CouponRedemption::get( $account_code );
			if ( ! is_object( $coupon_redemption ) || ! is_object( $coupon_redemption->coupon  ) )
			{
				return NULL;
			}

			$coupon = $coupon_redemption->coupon->get();

			if ( ! is_object( $coupon ) || ! isset( $coupon->coupon_code ) )
			{
				return NULL;
			}

			return $coupon->coupon_code;
		}// end try
		catch ( Exception $e )
		{
			return NULL;
		}
	}// end coupon_code

	/**
	 * respond to step 2 signup form post response from recurly
	 */
	private function thankyou()
	{
		if ( ! $account = $this->get_account_from_token() )
		{
			// this was a billing info update, we don't want to give them
			// the new account thank you page...
			return;
		}

		if ( ! $account->account_code )
		{
			// @todo we might want to show an error of some sort in this case.
			// according to the Recurly docs, this should never happen.
			return FALSE;
		}

		$current_user = wp_get_current_user();

		$user = $current_user->ID ? $current_user : get_user_by( 'email', $account->email );

		if ( $account->account_code == go_recurly()->get_or_create_account_code( $user ) )
		{
			$meta_vals = $this->admin()->recurly_sync( $user );

			// note: this function will delete the user cache as a side-effect,
			// so, login_user must be called AFTER this function
			go_subscriptions()->send_welcome_email( $user->ID, $meta_vals );
		}

		// we only want to set a durable login on a user who is already
		// logged in at this point
		if ( $current_user )
		{
			// they were logged in when signing up and they were redirected
			// here and the account_code matches.  Safe to login durable.
			go_subscriptions()->login_user( $current_user->ID, TRUE );
		}

		wp_redirect( $this->config['thankyou_path'] );
		exit;
	}//end thankyou
}//end class

/**
 * singleton function for go_recurly
 */
function go_recurly()
{
	global $go_recurly;

	if ( ! isset( $go_recurly ) || ! is_object( $go_recurly ) )
	{
		$go_recurly = new GO_Recurly();
	}

	return $go_recurly;
}//end go_recurly