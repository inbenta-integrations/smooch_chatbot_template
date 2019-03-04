<?php
namespace Inbenta\SmoochConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\SmoochConnector\ExternalAPI\SmoochAPIClient;
use Inbenta\SmoochConnector\ExternalDigester\SmoochDigester;
use Inbenta\SmoochConnector\HyperChatAPI\SmoochHyperChatClient;
use \Firebase\JWT\JWT; // https://github.com/firebase/php-jwt

class SmoochConnector extends ChatbotConnector
{

	public function __construct($appPath)
	{
		// Initialize and configure specific components for Smooch
		try {
			parent::__construct($appPath);

			// Initialize base components
			$request = file_get_contents('php://input');
			$conversationConf = array('configuration' => $this->conf->get('conversation.default'), 'userType' => $this->conf->get('conversation.user_type'), 'environment' => $this->environment);
			$this->session 		= new SessionManager($this->getExternalIdFromRequest());
			$this->botClient 	= new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

			// Retrieve Smooch tokens from ExtraInfo and update configuration
			$this->getTokensFromExtraInfo();

			// Try to get the translations from ExtraInfo and update the language manager
			$this->getTranslationsFromExtraInfo('smooch','translations');

			// Initialize Hyperchat events handler
			if ($this->conf->get('chat.chat.enabled')) {
				$chatEventsHandler = new SmoochHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
				$chatEventsHandler->handleChatEvent();
			}
			// Instance application components
			$externalClient 		= new SmoochAPIClient($this->conf->get('smooch.jwt'), $request); // Instance Smooch client
			$chatClient 			= new SmoochHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient); // Instance HyperchatClient for Smooch
			$externalDigester 		= new SmoochDigester($this->lang, $this->conf->get('conversation.digester')); // Instance Smooch digester
			$this->initComponents($externalClient, $chatClient, $externalDigester);
		}
		catch (Exception $e) {
			echo json_encode(["error" => $e->getMessage()]);
			die();
		}
	}

	/**
	 *	Retrieve Smooch tokens from ExtraInfo
	 */
	public function getRequestInfo()
	{
		return file_get_contents('php://input');
	}

	/**
	 *	Retrieve Smooch tokens from ExtraInfo
	 */
	protected function getTokensFromExtraInfo()
	{
		$tokens = [];
		$extraInfoDataSmooch = $this->botClient->getExtraInfo('smooch');
		foreach ($extraInfoDataSmooch->results as $element) {
			if($element->name == "app_tokens"){
				$environment = $this->environment;
				$tokens = $element->value->$environment[0];
			}
		}
		$key = $tokens->key;
		$secret = $tokens->secret;
		$param = array(
		    "scope" => "app"
		);
		$jwt = JWT::encode($param, $secret, 'HS256', $key);
		// Store tokens in conf
		$this->conf->set('smooch.jwt', $jwt);
	}

	/**
	 *	Return external id from request (Hyperchat of Smooch)
	 */
	protected function getExternalIdFromRequest()
	{
		// Try to get user_id from a Smooch message request
		$externalId = SmoochAPIClient::buildExternalIdFromRequest();
		if (is_null($externalId)) {
			// Try to get user_id from a Hyperchat event request
			$externalId = SmoochHyperChatClient::buildExternalIdFromRequest($this->conf->get('chat.chat'));
		}
		if (empty($externalId)) {
			$api_key = $this->conf->get('api.key');
			if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
				// Create a temporary session_id from a HyperChat webhook linking request
				$externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
			} else {
				throw new Exception("Invalid request");
				die();
			}
		}
		return $externalId;
	}

	/**
     * 	Send messages to the external service. Messages should be formatted as a ChatbotAPI response
     */
	protected function sendMessagesToExternal( $messages )
	{
		// Digest the bot response into the external service format
		$digestedBotResponse = $this->digester->digestFromApi($messages,  $this->session->get('lastUserQuestion'));
		foreach ($digestedBotResponse as $message) {
			if(isset($message['role'])){
				$this->externalClient->sendMessage($message);
			}else{
				$this->externalClient->sendMultipleMessage($message);
			};
		}
	}
}
