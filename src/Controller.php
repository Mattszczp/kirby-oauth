<?php

namespace Thathoff\Oauth;

use Kirby\Cms\Panel;
use Kirby\Cms\Response;
use Kirby\Http\Header;
use Kirby\Http\Uri;
use Kirby\Toolkit\F;
use Kirby\Toolkit\View;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;

class Controller
{
    private $kirby = null;
    private $providers = null;
    private $session = null;

    public function __construct()
    {
        $this->kirby = kirby();
        $this->session = $this->kirby->session();
        $this->providers = new ProvidersManager($this->kirby);
    }

    public function index() {
        if ($this->kirby->user()) {
            $this->goToPanel();
        }

        $error = $this->session->get('oauthError');
        $this->session->remove('oauthError');

        return self::view("index", [
            'icons'     => F::read($this->kirby->root('panel') . '/dist/img/icons.svg'),
            'assetUrl'  => $this->kirby->url('media') . '/panel/' . md5($this->kirby->version()),
            'config'    => $this->kirby->option('panel'),
            'providers' => $this->providers,
            'error'     => $error,
            'baseUrl'   => new Uri('oauth/login'),
        ]);
    }

    public function providers()
    {
        $error = $this->session->get('oauthError');
        $this->session->remove('oauthError');

        return self::view("providers", [
            'providers' => $this->providers,
            'baseUrl'   => new Uri('oauth/login'),
            'error'     => $error,
        ]);
    }

    public function settings()
    {
        return [
            'onlyOauth' => $this->kirby->option('thathoff.oauth.onlyOauth', false),
            'enabled' => count($this->providers) > 0,
        ];
    }

    public function login($provider = null)
    {
        if (!$provider = $this->providers->get($provider)) {
            $this->error("Oauth Provider not found!");
        }

        // Got an error, probably user denied access
        if (get('error')) {
            $this->error(get('error'));
        }

        // If we don't have an authorization code then get one
        if (!$code = get('code')) {
            $authorizationUrl = $provider->getAuthorizationUrl();
            $this->session->set('oauth2state', $provider->getState());

            // Redirect the user to the authorization URL.
            header('Location: ' . $authorizationUrl);
            exit;
        }

        // we already have a user just to to panel
        if ($this->kirby->user()) {
            $this->goToPanel();
        }

        // State is invalid, possible CSRF attack in progress
        if (empty(get('state')) || (get('state') !== $this->session->get('oauth2state'))) {
            $this->session->remove('oauth2state');
            $this->error('Invalid state');
        }

        try {
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);

            // We got an access token, let's now get the owner details
            $ownerDetails = $provider->getResourceOwner($token);

            // Use these details to login
            $this->loginUser($ownerDetails);
        } catch (\Exception $e) {
            // Failed to get user details
            $this->error($e->getMessage());
        }

        $this->error();
    }

    public static function view($name, $vars = null)
    {
        $view = new View(__dir__ . '/../views/' . $name . '.php', $vars);
        return new Response($view->render());
    }

    public static function handle($options)
    {
        $options = explode("/", trim($options, "/"));
        $method = "index";

        if (!empty($options[0])) {
            $method = array_shift($options);
        }

        $instance = new Controller();
        if (method_exists($instance, $method)) {
            if ($result = call_user_func_array([$instance, $method], $options)) {
                return $result;
            }
        }

        Header::notfound();
        return "Not found!";
    }

    private function loginUser(ResourceOwnerInterface $oauthUser)
    {
        $oauthUserData = $oauthUser->toArray();

        $vars = ['name', 'email', 'email_verified', 'hd'];
        
        //Azure Active Directory doesn't use "email" for email address, but "upn" for User Principal Name, and the email is always verified in Azure AD tenant
        if(isset($oauthUserData["upn"])) {
            $email = $oauthUserData["upn"];
            $email_verified = true;
        }

        foreach($vars as $var) {
            $$var = isset($oauthUserData[$var]) ? $oauthUserData[$var] : null;
        }

        if (!$email) {
            $this->error("E-mail address missing missing!");
        }

        if ($email_verified === false) {
            $this->error("E-mail address not verified!");
        }

        if (!$kirbyUser = $this->kirby->user($email)) {
            $onlyExistingUsers = $this->kirby->option('thathoff.oauth.onlyExistingUsers', false);

            if ($onlyExistingUsers) {
                $this->error("User missing and creating users is disabled!");
            }

            if (!$this->checkWhiteLists($email)) {
                $this->error("Access denied for $email.");
            }

            $this->kirby->impersonate('kirby');
            $kirbyUser = $this->kirby->users()->create([
                'name'      => $name,
                'password'  => bin2hex(random_bytes(32)),
                'email'     => $email,
                'role'      => $this->kirby->option('thathoff.oauth.defaultRole', 'admin'),
            ]);
        }

        $kirbyUser->loginPasswordless();
        $this->goToPanel();
    }

    private function checkWhiteLists($email)
    {
        $domainWhitelist = $this->kirby->option('thathoff.oauth.domainWhitelist', []);
        $emailWhitelist = $this->kirby->option('thathoff.oauth.emailWhitelist', []);
        $allowEveryone = $this->kirby->option('thathoff.oauth.allowEveryone', false);

        if ($allowEveryone) {
            return true;
        }

        if (is_array($emailWhitelist) && in_array($email, $emailWhitelist)) {
            return true;
        }

        $domain = substr($email, strpos($email, "@") + 1);
        if (is_array($domainWhitelist) && in_array($domain, $domainWhitelist)) {
            return true;
        }

        return false;
    }

    private function error($message = null)
    {
        $this->session->set("oauthError", $message);
        go("oauth");
    }

    private function goToPanel()
    {
        go("panel");
    }
}
