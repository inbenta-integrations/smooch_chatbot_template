### OBJECTIVE
This template has been implemented in order to develop Smooch bots that consume from the Inbenta Chatbot API with the minimum configuration and effort. It uses some libraries to connect the Chatbot API with Smooch. The main library of this template is Smooch Connector, which extends from a base library named [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector), built to be used as a base for different external services like Skype, Line, etc.

This template includes **/conf** and **/lang** folders, which have all the configuration and translations required by the libraries, and a small file **server.php** which creates a SmoochConnector’s instance in order to handle all the incoming requests.

### FUNCTIONALITIES
This bot template inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options
* Polar questions
* Chained answers
* Content ratings (yes/no + comment)
* Escalate to HyperChat after a number of no-results answers
* Escalate to HyperChat after a number of negative ratings
* Escalate to HyperChat when matching with an 'Escalation FAQ'
* Send information to webhook through forms
* Custom FAQ title in button when displaying multiple options
* Retrieve Smooch tokens from ExtraInfo
* Send a button that opens a configured URL along with the answer

### INSTALLATION
It's pretty simple to get this UI working. The mandatory configuration files are included by default in `/conf/custom` to be filled in, so you have to provide the information required in these files:

* **File 'api.php'**
    Provide the API Key and API Secret of your Chatbot Instance.

* **File 'environments.php'**
    Here you can define regexes to detect `development` and `preproduction` environments. If the regexes do not match the current conditions or there isn't any regex configured, `production` environment will be assumed.

Also, this template needs the Smooch App `Key` and `Secret` that will be retrieved from ExtraInfo. Here are the steps to create the full ExtraInfo object:

* Go to **Knowledge -> Extra Info -> Manage groups and types** and click on **Add new group**. Name it `smooch`.
* Go to **Manage groups and types -> smooch -> Add type**. Name it `app_tokens`.
* Add 3 new properties named `development`, `preproduction` and `production` of type *multiple*.
* Inside each of those 3 properties, create 2 sub-properties with names `key` and `secret` and type *text*.

Now, create the ExtraInfo objects by clicking the **New entry** button:
* Name the entry `app_tokens`.
* Select the group ‘smooch’ and the type ‘app_tokens’.
* Insert the Smoch's app Key/Secret that you can find in your Smooch's App "Settings" tab.

Note that you can have different Smooch App's set in for the different environments: development, preproduction or production.

Remember to publish the new ExtraInfo by clicking the **Post** button.

### HOW TO CUSTOMIZE
**From configuration**

For a default behavior, the only requisite is to fill the basic configuration (more information in `/conf/README.md`). There are some extra configuration parameters in the configuration files that allow you to modify the basic-behavior.


**Custom Behaviors**

If you need to customize the bot flow, you need to extend the class `SmoochConnector`, included in the `/lib/SmoochConnector` folder. You can modify 'SmoochConnector' methods and override all the parent methods from `ChatbotConnector`.

For example, when the bot is configured to escalate with an agent, a conversation in HyperChat starts. If your bot needs to use an external chat service, you should override the parent method `escalateToAgent` and set up the external service:
```php
	//Tries to start a chat with an agent with an external service
	protected function escalateToAgent()
	{
		$useExternalService = $this->conf->get('chat.useExternal');
		
		if ($useExternalService) {
		    // Inform the user that the chat is being created
			$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
			
		    // Create a new instance for the external client
		    $externalChat = New SomeExternalChatClass($this->conf->get('chat.externalConf'));
			$externalChat->openChat();
		} else {
			// Use the parent method to escalate to HyperChat
			parent::escalateToAgent();
		}
	}
```


**HyperChat escalation by no-result answer and negative content rating**

If your bot needs integration with HyperChat, fill the chat configuration at `/conf/conf-path/chat.php`.
Add the target `webhook url` in your Case Management *Backstage instance ->Settings->Chat->Webhooks* and subscribe to the following events: `invitations:new`,`invitations:accept`,`forever:alone`,`chats:close`,`messages:new`. When subscribing to the events in Backstage, you have to point to the `/server.php` file in order to handle the events from HyperChat.

Configuration parameter `triesBeforeEscalation` sets the number of no-results answers after which the bot should escalate to an agent. Parameter `negativeRatingsBeforeEscalation` sets the number of negative ratings after which the bot should escalate to an agent.


**Escalation with FAQ**

If your bot has to escalate to HyperChat when matching a specific FAQ, the content needs to meet a few requisites:
- Dynamic setting named `ESCALATE`, non-indexable, visible, `Text` box-type with `Allow multiple objects` option checked
- In the content, add a new object to the `Escalate` setting (with the plus sign near the setting name) and type the text `TRUE`.

After a Restart Project Edit and Sync & Restart Project Live, your bot should escalate when this FAQ is matched.
Note that the `server.php` file has to be subscribed to the required HyperChat events as described in the previous section.

### DEPENDENCIES
This application imports `inbenta/chatbot-api-connector` as a Composer dependency, that includes `symfony/http-foundation@^3.1` and `guzzlehttp/guzzle@~6.0` as dependencies too.
