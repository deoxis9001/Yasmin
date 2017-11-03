<?php
/**
 * Yasmin
 * Copyright 2017 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Yasmin/blob/master/LICENSE
*/

namespace CharlotteDunois\Yasmin\Models;

/**
 * @internal
 */
class Storage extends \CharlotteDunois\Yasmin\Utils\Collection
    implements \CharlotteDunois\Yasmin\Interfaces\StorageInterface {
    
    protected $client;
    
    function __construct(\CharlotteDunois\Yasmin\Client $client, array $data = null) {
        parent::__construct($data);
        $this->client = $client;
    }
    
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        }
        
        throw new \Exception('Unknown property '.\get_class($this).'::'.$name);
    }
}
