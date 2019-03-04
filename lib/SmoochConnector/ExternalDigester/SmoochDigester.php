<?php
namespace Inbenta\SmoochConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;

class SmoochDigester extends DigesterInterface
{

	protected $conf;
	protected $channel;
	protected $langManager;
	protected $externalMessageTypes = array(
		'text',
		'postback',
		'file',
		'image',
		'reply'
	);

	public function __construct($langManager, $conf)
	{
		$this->langManager = $langManager;
		$this->channel = 'Smooch';
		$this->conf = $conf;
	}

	/**
	*	Returns the name of the channel
	*/
	public function getChannel()
	{
		return $this->channel;
	}

	/**
	**	Checks if a request belongs to the digester channel
	**/
	public static function checkRequest($request)
	{
		$request = json_decode($request);

		$isPage 	 = isset($request->object) && $request->object == "page";
		$isMessaging = isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging);
		if ($isPage && $isMessaging && count((array)$request->entry[0]->messaging)) {
			return true;
		}
		return false;
	}

	/**
	**	Formats a channel request into an Inbenta Chatbot API request
	**/
	public function digestToApi($request)
	{
		$request = json_decode($request);
		if(isset($request->messages) && isset($request->messages[0])){
			$messages = $request->messages;
		}else if(isset($request->postbacks) && isset($request->postbacks[0])){
			$messages = $request->postbacks;
		}
		else {
			return [];
		}
		$output = [];

		foreach ($messages as $msg) {
			$msgType = $this->checkExternalMessageType($msg);
			$digester = 'digestFromSmooch' . ucfirst($msgType);

			//Check if there are more than one responses from one incoming message
			$digestedMessage = $this->$digester($msg);
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $message) {
					$output[] = $message;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Formats an Inbenta Chatbot API response into a channel request
	**/
	public function digestFromApi($request, $lastUserQuestion='')
	{
		//Parse request messages
		if (isset($request->answers) && is_array($request->answers)) {
			$messages = $request->answers;
		} elseif ($this->checkApiMessageType($request) !== null) {
			$messages = array('answers' => $request);
		} else {
			throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
		}

		$output = [];
		foreach ($messages as $msg) {
			$msgType = $this->checkApiMessageType($msg);
			$digester = 'digestFromApi' . ucfirst($msgType);
			$digestedMessage = $this->$digester($msg, $lastUserQuestion);

			//Check if there are more than one responses from one incoming message
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $message) {
					$output[] = $message;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Classifies the external message into one of the defined $externalMessageTypes
	**/
	protected function checkExternalMessageType($message)
	{
		foreach ($this->externalMessageTypes as $type) {
			$checker = 'isSmooch' . ucfirst($type);

			if ($this->$checker($message)) {
				return $type;
			}
		}
	}

	/**
	**	Classifies the API message into one of the defined $apiMessageTypes
	**/
	protected function checkApiMessageType($message)
	{
		foreach ( $this->apiMessageTypes as $type ) {
			$checker = 'isApi' . ucfirst($type);

			if ($this->$checker($message)) {
				return $type;
			}
		}
		return null;
	}

	/********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/

	protected function isSmoochText($message)
	{
		$isText = isset($message->type) && $message->type === "text" && !isset($message->payload);
		return $isText;
	}

	protected function isSmoochReply($message)
	{
		$isReply = isset($message->type) && $message->type === "text" && isset($message->payload);
		return $isReply;
	}

	protected function isSmoochPostback($message)
	{
		return isset($message->action) && isset($message->action->type) && $message->action->type === "postback";
	}

	protected function isSmoochImage($message)
	{
		return isset($message->type) && $message->type === "image";
	}

	protected function isSmoochFile($message)
	{
		return isset($message->type) && $message->type === "file";
	}

	/********************** API MESSAGE TYPE CHECKERS **********************/

	protected function isApiAnswer($message)
	{
		return isset($message->type) && $message->type == 'answer';
	}

	protected function isApiPolarQuestion($message)
	{
		return isset($message->type) && $message->type == "polarQuestion";
	}

	protected function isApiMultipleChoiceQuestion($message)
	{
		return isset($message->type) && $message->type == "multipleChoiceQuestion";
	}

	protected function isApiExtendedContentsAnswer($message)
	{
		return isset($message->type) && $message->type == "extendedContentsAnswer";
	}

	protected function hasTextMessage($message) {
		return isset($message->message) && is_string($message->message);
	}


	/********************** SMOOCH TO API MESSAGE DIGESTERS **********************/

	protected function digestFromSmoochText($message)
	{
		return array(
			'message' => $message->text
		);
	}

	protected function digestFromSmoochReply($message)
	{
		return json_decode($message->payload, true);
	}

	protected function digestFromSmoochPostback($message)
	{
		return json_decode($message->action->payload, true);
	}

	protected function digestFromSmoochFile($message)
	{
		$file = $message->mediaUrl;
		return array(
			'message' => $file
		);
	}

	protected function digestFromSmoochImage($message)
	{
		$image = $message->mediaUrl;
		return array(
			'message' => $image
		);
	}


	/********************** CHATBOT API TO SMOOCH MESSAGE DIGESTERS **********************/

	protected function digestFromApiAnswer($message)
	{
		$output = array();
		$urlButtonSetting = isset($this->conf['url_buttons']['attribute_name']) ? $this->conf['url_buttons']['attribute_name'] : '';

		if (strpos($message->message, '<img')) {
			// Handle a message that contains an image (<img> tag)
			$output['multiple_output'] = $this->handleMessageWithImages($message);
		} elseif (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
			// Send a button that opens an URL
			$output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
		} else {
			// Add simple text-answer
			$output = [
				'role' => 'appMaker',
				'type' => 'text',
				'text' => strip_tags($message->message)
			];
		}
		return $output;
	}

	protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
	{
		$isMultiple = isset($message->flags) && in_array('multiple-options', $message->flags);
		$buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';

		$items = array();
		foreach ($message->options as $option) {
			$actions []=
	            array(
	            	"type" => "postback",
					"text" => $isMultiple && isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->label,
					"payload" => json_encode([
						"message" => $lastUserQuestion,
						"option" => $option->value
	            	])
				);
		}
        $output = [
        	"role" => "appMaker",
        	"type" => "text",
        	"text" => strip_tags($message->message),
        	"actions" => $actions
        ];
		return $output;

	}

	protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
	{
		$items = array();
		foreach ($message->options as $option) {
			$actions []=
	            array(
	            	"type" => "postback",
					"text" => $this->langManager->translate($option->label),
					"payload" => json_encode([
						"message" => $lastUserQuestion,
						"option" => $option->value
	            	])
				);
		}
        $output = [
        	"role" => "appMaker",
        	"type" => "text",
        	"text" => strip_tags($message->message),
        	"actions" => $actions
        ];
		return $output;
	}

    protected function digestFromApiExtendedContentsAnswer($message)
    {
        $buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';

		$items = array();
		foreach ($message->subAnswers as $option) {
			$actions []=
	            array(
	            	"type" => "postback",
					"text" => isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->message,
					"payload" => json_encode([
						"extendedContentAnswer" => $option
	            	])
				);
		}
        $output = [
        	"role" => "appMaker",
        	"type" => "text",
        	"text" => strip_tags($message->message),
        	"actions" => $actions
        ];
		return $output;
    }


	/********************** MISC **********************/

	public function buildContentRatingsMessage($ratingOptions, $rateCode)
	{
		$items = array();
		foreach ($ratingOptions as $option) {
			$actions []=
	            array(
	            	"type" => "reply",
					"text" => $this->langManager->translate( $option['label'] ),
					"metadata" => array("type" =>"rating"), // Metadata should be and object
					"payload" => json_encode([
						'askRatingComment' => isset($option['comment']) && $option['comment'],
						'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
						'ratingData' =>	[
							'type' => 'rate',
							'data' => array(
								'code' 	  => $rateCode,
								'value'   => $option['id'],
								'comment' => null
							)
						]
					], true)
				);
		}
        $output = [
        	"role" => "appMaker",
        	"type" => "text",
        	"text" => $this->langManager->translate('rate_content_intro'),
        	"actions" => $actions
        ];
		return $output;
	}

	/**
	 *	Splits a message that contains an <img> tag into text/image/text and displays them in Smooch
	 */
	protected function handleMessageWithImages($message)
	{
		//Remove \t \n \r and HTML tags (keeping <img> tags)
		$text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($message->message, "<img>"));
		//Capture all IMG tags and return an array with [text,imageURL,text,...]
		$parts = preg_split('/<\s*img.*?src\s*=\s*"(.+?)".*?\s*\/?>/', $text,-1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		$output = array();
		for ($i = 0; $i < count($parts); $i++) {
			if(substr($parts[$i],0,4) == 'http'){
				$message = array(
					'type' => 'image',
					'role' => 'appMaker',
					'mediaUrl' => $parts[$i]
				);
			}else{
				$message = array(
					'type' => 'text',
					'role' => 'appMaker',
					'text' => $parts[$i]
				);
			}
			$output[] = $message;
		}
		return array($output);
	}

    /**
     *	Sends the text answer and displays an URL button
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {

        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [$urlButton];
        }

		$items = array();
		foreach ($urlButton as $button) {
			// If any of the urlButtons has any invalid/missing url or title, abort and send a simple text message
            if (!isset($button->$buttonURLProp) || !isset($button->$buttonTitleProp) || empty($button->$buttonURLProp) || empty($button->$buttonTitleProp)) {
                return [
		        	"role" => "appMaker",
		        	"type" => "text",
		        	"text" => strip_tags($message->message)
		        ];
            }
			$actions []=
	            array(
	            	"type" => "webview",
					"text" => $button->$buttonTitleProp,
					"uri" => $button->$buttonURLProp,
					"fallback" => $button->$buttonURLProp,
				);
		}
        $output = [
        	"role" => "appMaker",
        	"type" => "text",
        	"text" => strip_tags($message->message),
        	"actions" => $actions
        ];
		return $output;
    }

    public function buildEscalationMessage()
    {
        $actions = array();
        $escalateOptions = [
            [
                "label" => 'yes',
                "escalate" => true
            ],
            [
                "label" => 'no',
                "escalate" => false
            ],
        ];
		foreach ($escalateOptions as $option) {
			$actions []=
	            array(
	            	"type" => "postback",
					"text" => $this->langManager->translate($option['label']),
					"payload" => json_encode([
                    	'escalateOption' => $option['escalate'],
                	], true)
				);
		}
        $output = [
        	"role" => "appMaker",
        	"type" => "text",
        	"text" => $this->langManager->translate('ask_to_escalate'),
        	"actions" => $actions
        ];
		return $output;
    }
}
