<?php

use League\OAuth2\Client\Provider\Google;
use LimeSurvey\PluginManager\SmtpOauthPluginBase;
use PHPMailer\PHPMailer\PHPMailer;

class GoogleOAuthSMTP extends SmtpOauthPluginBase
{
    protected $storage = 'DbStorage';
    protected static $description = 'Core: Adds Google OAuth support for email sending';
    protected static $name = 'GoogleOAuthSMTP';

    /** @inheritdoc, this plugin doesn't have any public method */
    public $allowedPublicMethods = [];

    /** @inheritdoc */
    protected $credentialAttributes = ['clientId', 'clientSecret'];

    protected $settings = [
        'help' => [
            'type' => 'info',
            'content' => '',
        ],
        'clientId' => [
            'type' => 'string',
            'label' => 'Client ID',
        ],
        'clientSecret' => [
            'type' => 'string',
            'label' => 'Client Secret',
        ],
    ];

    public function init()
    {
        $this->subscribe('listSMTPOAuthPlugins');
        $this->subscribe('afterSelectSMTPOAuthPlugin');
        $this->subscribe('newSMTPOAuthInitialization');
        $this->subscribe('beforePrepareRedirectToAuthPage');
        $this->subscribe('beforeRedirectToAuthPage');   // Handler defined in SmtpOauthPluginBase
        $this->subscribe('afterReceiveOAuthResponse');  // Handler defined in SmtpOauthPluginBase

        $this->subscribe('beforeEmail');
        $this->subscribe('beforeSurveyEmail', 'beforeEmail');
        $this->subscribe('beforeTokenEmail', 'beforeEmail');
    }

    /**
     * @inheritdoc
     * Update the information content
     */
    public function getPluginSettings($getValues = true)
    {
        $settings = parent::getPluginSettings($getValues);
        $settings['help']['content'] = $this->getHelpContent();
        $settings['clientId']['label'] = gT("Client ID");
        $settings['clientSecret']['label'] = gT("Client Secret");

        $currentEmailSetting = $this->getCurrentEmailSetting();
        if (!empty($currentEmailSetting)) {
            $settings['currentEmail'] = $currentEmailSetting;
        }

        return $settings;
    }

    private function getHelpContent()
    {
        $this->subscribe('getPluginTwigPath');
        $data = [
            'redirectUri' => $this->getRedirectUri(),
            'isHttps' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        ];
        return Yii::app()->twigRenderer->renderPartial('/Help.twig', $data);

        // Translations here just so the translations bot can pick them up.
        $lang = [
            gT("Help"),
            gT("Prerequisites:"),
            gT("Access LimeSurvey over HTTPS."),
            gT("Currently not served over HTTPS"),
            gT("Instructions:"),
            gT("Setup the OAuth 2.0 Web Application in %s."),
            gT("Google Cloud Platform Console"),
            gT("Redirect URI:"),
            gT("You can find more details %s."),
            gT("here"),
            gT("Activate the plugin."),
            gT("Set the 'Client ID' and 'Client Secret' below and save the settings."),
            gT("Click the 'Get Token' button to open Google's consent screen in a new window."),
            gT("Follow the steps in the consent screen and check the requested permissions."),
            gT("Switch the 'Enabled' setting to 'On' and save."),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getProvider($credentials)
    {
        $redirectUri = $this->getRedirectUri();
        $params = [
            'clientId' => $credentials['clientId'],
            'clientSecret' => $credentials['clientSecret'],
            'redirectUri' => $redirectUri,
            'accessType' => 'offline',
            'prompt' => 'consent',
        ];
        return new Google($params);
    }

    /**
     * Adds view's path to twig system
     */
    public function getPluginTwigPath()
    {
        $viewPath = dirname(__FILE__) . "/views";
        $this->getEvent()->append('add', array($viewPath));
    }

    /**
     * Adds the plugin to the list of SMTP OAuth plugins
     */
    public function listSMTPOAuthPlugins()
    {
        $event = $this->getEvent();
        $event->append('oauthplugins', [
            'google' => $this->getSmtpOAuthPluginInfo()
        ]);
    }

    /**
     * Handles the newSMTPOAuthInitialization event, triggered during LimeMailer initialization
     */
    public function newSMTPOAuthInitialization()
    {
        try {
            $credentials = $this->getCredentials();
        } catch (Exception $ex) {
            $this->log("GoogleOAuthSMTP is enabled but credentials are incomplete.", CLogger::LEVEL_WARNING);
            return;
        }

        $refreshToken = $this->get("refreshToken");
        if (empty($refreshToken)) {
            $this->log("GoogleOAuthSMTP is enabled but there is no refresh token stored.", CLogger::LEVEL_WARNING);
            return;
        }

        $emailAddress = $this->get("email");
        if (empty($emailAddress)) {
            $this->log("GoogleOAuthSMTP is enabled but there is no email address. Please generate a new token.", CLogger::LEVEL_WARNING);
            return;
        }

        $event = $this->getEvent();

        $provider = $this->getProvider($credentials);
        $config = [
            'provider' => $provider,
            'clientId' => $credentials['clientId'],
            'clientSecret' => $credentials['clientSecret'],
            'refreshToken' => $refreshToken,
            'userName' => $emailAddress,
        ];

        $event->set('oauthconfig', $config);

        $limeMailer = $event->get('mailer');
        $limeMailer->Host = 'smtp.gmail.com';
        $limeMailer->Port = 465;
        $limeMailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    }

    /**
     * Handles the afterSelectSMTPOAuthPlugin event, triggered when the plugin
     * is selected as the SMTP OAuth plugin in Global Settings
     */
    public function afterSelectSMTPOAuthPlugin()
    {
        $setupStatus = $this->getSetupStatus();
        if ($setupStatus !== self::SETUP_STATUS_VALID_REFRESH_TOKEN) {
            $event = $this->getEvent();
            $event->set('warning', sprintf(gT("The %s plugin is not configured correctly. Please check the plugin settings."), self::getName()));
        }
    }

    /**
     * @inheritdoc
     */
    protected function getAuthorizationOptions()
    {
        return [
            'scope' => [
                'https://mail.google.com/'
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function beforeEmail()
    {
        // Don't do anything if the current plugin is not the one selected.
        if (!$this->isCurrentSMTPOAuthHandler()) {
            return;
        }

        $limeMailer = $this->getEvent()->get('mailer');
        // Set "Reply To" because Gmail overrides the From/Sender with the logged user.
        $limeMailer->AddReplyTo($limeMailer->From, $limeMailer->FromName);
    }

    /**
     * @inheritdoc
     */
    protected function getProviderName()
    {
        return gT('Google');
    }

    public function beforePrepareRedirectToAuthPage()
    {
        $event = $this->getEvent();
        $event->set('width', 600);
        $event->set('height', 700);
        $event->set('providerName', $this->getProviderName());

        $setupStatus = $this->getSetupStatus();
        $description = $this->getSetupStatusDescription($setupStatus);
        $event->setContent($this, $description);
    }
}
