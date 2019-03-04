<?php
namespace Inbenta\SmoochConnector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\SmoochConnector\ExternalAPI\SmoochAPIClient;

class SmoochHyperChatClient extends HyperChatClient
{

    //Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        $request = json_decode(file_get_contents('php://input'), true);

        $externalUserId = SmoochAPIClient::getIdFromExternalId($externalId);
        if (is_null($externalUserId)) {
            return null;
        }
        $externalAppId = SmoochAPIClient::getAppIdFromExternalId($externalId);
        if (is_null($externalAppId)) {
            return null;
        }
        $externalClient = new SmoochAPIClient($appConf->get('smooch.jwt'),$request);
        $externalClient->setSenderFromId( $externalUserId, $externalAppId );
        return $externalClient;
    }

    public static function buildExternalIdFromRequest ($config)
    {
        $request = json_decode(file_get_contents('php://input'), true);

        $externalId = null;
        if (isset($request['trigger']) && isset($request['appId'])) {
            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $request);
        }
        return $externalId;
    }
}
