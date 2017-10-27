<?php
/**
 * Yasmin
 * Copyright 2017 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Yasmin/blob/master/LICENSE
*/

namespace CharlotteDunois\Yasmin\Models;

class DMChannel extends TextBasedChannel { //TODO: Implementation
    protected $ownerID;
    protected $recipients;
    
    function __construct(\CharlotteDunois\Yasmin\Client $client, array $channel) {
        parent::__construct($client, $channel);
        
        $this->ownerID = $channel['owner_id'] ?? null;
        $this->recipients = $channel['recipients'] ?? array();
    }
    
    /**
     * @inheritdoc
     */
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        }
        
        switch($name) {
            case 'owner':
                if($this->client->users->has($this->ownerID)) {
                    return $this->client->users->get($this->ownerID);
                }
            break;
        }
        
        return parent::__get($name);
    }
}