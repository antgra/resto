<?php
/*
 * Copyright 2014 Jérôme Gasperi
 *
 * Licensed under the Apache License, version 2.0 (the "License");
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at:
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

/**
 * RESTo REST router for POST requests
 * 
 *    api/licenses/{licenseid}/sign                 |  Sign license
 *    api/user/connect                              |  Connect user
 *    api/user/disconnect                           |  Disconnect user
 *    api/user/resetPassword                        |  Reset password
 * 
 *    collections                                   |  Create a new {collection}            
 *    collections/{collection}                      |  Insert new product within {collection}
 *
 *    user/cart                                     |  Add new item in user cart
 *    user/orders                                   |  Send an order for user
 *    user/groups                                   |  Set groups for user (only admin)
 * 
 *    users                                         |  Add a user
 *    
 */
class RestoRoutePOST extends RestoRoute {
    
    /**
     * Constructor
     */
    public function __construct($context, $user) {
        parent::__construct($context, $user);
    }
   
    /**
     * Process HTTP POST request
     * 
     * @param array $segments
     */
    public function route($segments) {
        
        /*
         * Input data for POST request
         */
        $data = RestoUtil::readInputData($this->context->uploadDirectory);
        
        switch($segments[0]) {
            case 'api':
                return $this->POST_api($segments, $data);
            case 'collections':
                return $this->POST_collections($segments, $data);
            case 'user':
                return $this->POST_user($segments, $data);
            case 'users':
                return $this->POST_users($segments, $data);
            default:
                return $this->processModuleRoute($segments, $data);
        }
    }
   
    /**
     * 
     * Process HTTP POST request on api
     * 
     *    api/licenses/{licenseid}/sign                |  Sign license
     *    api/user/connect                             |  Connect user
     *    api/user/disconnect                          |  Disconnect user
     * 
     * @param array $segments
     * @param array $data
     */
    private function POST_api($segments, $data) {
        
        if (!isset($segments[1])) {
            RestoLogUtil::httpError(404);
        }
        
        /*
         * api/licenses/{licenseid}/sign
         */
        if ($segments[1] === 'licenses') {
            
            if (isset($segments[3]) && $segments[3] === 'sign' && !isset($segments[4])) {
                if ($this->user->profile['email'] === 'unregistered') {
                    RestoLogUtil::httpError(403);
                }
                $licenses = $this->context->dbDriver->get(RestoDatabaseDriver::LICENSES, array(
                    'licenseId' => $segments[2]
                ));
                if (!isset($licenses[$segments[2]])) {
                    RestoLogUtil::httpError(400, 'Non existing license : ' . $segments[2]);
                }
                return $this->user->signLicense(new RestoLicense($this->context, $licenses[$segments[2]]));
            }
            
            RestoLogUtil::httpError(404);
            
        }
        
        /*
         * api/user
         */
        else if ($segments[1] === 'user') {
            
            if (!isset($segments[2])) {
                RestoLogUtil::httpError(404);
            }
            
            /*
             * api/user/connect
             */
            if ($segments[2] === 'connect' && !isset($segments[3])) {
                
                if (!isset($data['email']) || !isset($data['password'])) {
                    RestoLogUtil::httpError(400);
                }
                else {
                    try {
                        $profile = $this->context->dbDriver->get(RestoDatabaseDriver::USER_PROFILE, array(
                            'email' => strtolower($data['email']),
                            'password' => $data['password']
                        ));
                    } catch (Exception $ex) {
                        $profile = null;
                    }
                    $this->user = new RestoUser($profile, $this->context);
                }
                
                /*
                 * Disconnect user
                 */
                if ($this->user->profile['userid'] !== -1) {
                    $this->user->disconnect();
                }
                
                return $this->user->connect();
                
            }

            /*
             * api/user/disconnect
             */
            if ($segments[2] === 'disconnect' && !isset($segments[3])) {
                $this->user->disconnect();
                return RestoLogUtil::success('User disconnected');
            }
            
            /*
             * api/user/resetPassword
             */
            if ($segments[2] === 'resetPassword' && !isset($segments[3])) {
                if (!isset($data['email']) || !isset($data['password']) || !isset($data['url'])) {
                    RestoLogUtil::httpError(400);
                }
                return $this->resetUserPassword(strtolower($data['email']), $data['password'], $data['url']);
            }
            
            RestoLogUtil::httpError(404);
            
        }
        /*
         * Process module
         */
        else {
            return $this->processModuleRoute($segments, $data);
        }
        
    }

    /**
     * 
     * Process HTTP POST request on collections
     * 
     *    collections                                   |  Create a new {collection}            
     *    collections/{collection}                      |  Insert new product within {collection}
     * 
     * @param array $segments
     * @param array $data
     */
    private function POST_collections($segments, $data) {
        
        /*
         * No feature allowed
         */
        if (isset($segments[2]) ? $segments[2] : null) {
            RestoLogUtil::httpError(404);
        }
        
        if (isset($segments[1])) {
            $collection = new RestoCollection($segments[1], $this->context, $this->user, array('autoload' => true));
        }
        
        /*
         * Create new collection
         */
        if (!isset($collection)) {
            
            /*
             * Only a user with 'create' rights can POST a collection
             */
            if (!$this->user->hasRightsTo(RestoUser::CREATE)) {
                RestoLogUtil::httpError(403);
            }
            
            $collections = new RestoCollections($this->context, $this->user, array('autoload' => true));
            $collections->create($data);
            return RestoLogUtil::success('Collection ' . $data['name'] . ' created');
            
        }
        /*
         * Insert new feature in collection
         */
        else {
            
            /*
             * Only a user with 'update' rights on collection can POST feature
             */
            if (!$this->user->hasRightsTo(RestoUser::UPDATE, array('collection' => $collection))) {
                RestoLogUtil::httpError(403);
            }
            
            return $this->addFeatureToCollection($collection, $data);
            
        }
    }
   
    /**
     * 
     * Process HTTP POST request on users
     * 
     *    user/cart                                     |  Add new item in user cart
     *    user/orders                                   |  Send an order for user
     *
     * @param array $segments
     * @param array $data
     */
    private function POST_user($segments, $data) {
        
        if (!isset($segments[1]) || isset($segments[2])) {
            RestoLogUtil::httpError(404);
        }
        
        /*
         * user/cart
         */
        if ($segments[1] === 'cart') {
            
            $clear = isset($this->context->query['_clear']) ? filter_var($this->context->query['_clear'], FILTER_VALIDATE_BOOLEAN) : false;
            
            /*
             * Remove items first
             */
            if ($clear) {
                $this->user->getCart()->clear(true);
            }
            
            /*
             * Add items
             */
            $items = $this->user->getCart()->add($data, true);
            return $items !== false ? RestoLogUtil::success('Add items to cart', array('items' => $items)) : RestoLogUtil::error('Cannot add items to cart');
            
        }
      
        /*
         * user/orders
         */
        else if ($segments[1] === 'orders') {
            $order = $this->user->placeOrder($data);
            return $order ? RestoLogUtil::success('Place order', array('order' => $order)) : RestoLogUtil::error('Cannot place order');
        }

        /*
         * Unknown route
         */
        else {
            RestoLogUtil::httpError(404);
        }
        
    }
    
    /**
     * 
     * Process HTTP POST request on users
     * 
     *    users                                         |  Add a user
     *
     * @param array $segments
     * @param array $data
     */
    private function POST_users($segments, $data) {
        
        /*
         * No modifier allowed
         */
        if (isset($segments[1])) {
            RestoLogUtil::httpError(404);
        }
        
        return $this->createUser($data);
        
    }   

    /**
     * Create user
     * 
     * @param array $data
     */
    private function createUser($data) {
        
        if (!isset($data['email'])) {
            RestoLogUtil::httpError(400, 'Email is not set');
        }

        if ($this->context->dbDriver->check(RestoDatabaseDriver::USER, array('email' => $data['email']))) {
            RestoLogUtil::httpError(3000);
        }
        
        $redirect = isset($data['activateUrl']) ? '&redirect=' . rawurlencode($data['activateUrl']) : '';
        $userInfo = $this->context->dbDriver->store(RestoDatabaseDriver::USER_PROFILE, array(
            'profile' => array(
                'email' => $data['email'],
                'password' => isset($data['password']) ? $data['password'] : null,
                'username' => isset($data['username']) ? $data['username'] : null,
                'givenname' => isset($data['givenname']) ? $data['givenname'] : null,
                'lastname' => isset($data['lastname']) ? $data['lastname'] : null,
                'country' => isset($data['country']) ? $data['country'] : null,
                'organization' => isset($data['organization']) ? $data['organization'] : null,
                'flags' => isset($data['flags']) ? $data['flags'] : null,
                'topics' => isset($data['topics']) ? $data['topics'] : null,
                'activated' => 0
            ))
        );
        if (isset($userInfo)) {
            $activationLink = $this->context->baseUrl . '/api/users/' . $userInfo['userid'] . '/activate?act=' . $userInfo['activationcode'] . $redirect;
            $fallbackLanguage = isset($this->context->mail['accountActivation'][$this->context->dictionary->language]) ? $this->context->dictionary->language : 'en';
            if (!RestoUtil::sendMail(array(
                        'to' => $data['email'],
                        'senderName' => $this->context->mail['senderName'],
                        'senderEmail' => $this->context->mail['senderEmail'],
                        'subject' => $this->context->dictionary->translate($this->context->mail['accountActivation'][$fallbackLanguage]['subject'], $this->context->title),
                        'message' => $this->context->dictionary->translate($this->context->mail['accountActivation'][$fallbackLanguage]['message'], $this->context->title, $activationLink)
                    ))) {
                RestoLogUtil::httpError(3001);
            }
        }
        else {
            RestoLogUtil::httpError(500, 'Database connection error');
        }

        return RestoLogUtil::success('User ' . $data['email'] . ' created');
    }
    
    /**
     * Reset user password
     * 
     * @param string $email
     * @param string $password
     * @param string $url
     * 
     * @return type
     */
    private function resetUserPassword($email, $password, $url) {
        
        /*
         * Explod data['url'] into resourceUrl and queryString
         */
        $pair = explode('?', $url);
        if (!isset($pair[1])) {
            RestoLogUtil::httpError(403);
        }
        
        /*
         * Only initiator of reset password can change its email
         */
        $splittedUrl = explode('/', $pair[0]);
        if (strtolower(base64_decode($splittedUrl[count($splittedUrl) - 1])) !== $email) {
            RestoLogUtil::httpError(403);
        }
        
        $query = RestoUtil::queryStringToKvps($pair[1]);
        if (!isset($query['_tk']) || !$this->context->dbDriver->check(RestoDatabaseDriver::SHARED_LINK, array('resourceUrl' => $pair[0], 'token' => $query['_tk']))) {
            RestoLogUtil::httpError(403);
        }
        
        if ($this->context->dbDriver->get(RestoDatabaseDriver::USER_PASSWORD, array('email' => $email)) === str_repeat('*', 40)) {
            RestoLogUtil::httpError(3004);
        }
        
        if ($this->context->dbDriver->update(RestoDatabaseDriver::USER_PROFILE, array('profile' => array('email' => $email, 'password' => $password)))) {
            return RestoLogUtil::success('Password updated');
        }
        else {
            RestoLogUtil::httpError(400);
        }
        
    }
    
    /**
     * Add feature to collection 
     * 
     * @param RestoCollection $collection
     * @param array $data
     * 
     */
    private function addFeatureToCollection($collection, $data) {
        
        $feature = $collection->addFeature($data);
        
        /*
         * Store query
         */
        if ($this->context->storeQuery === true) {
            $this->user->storeQuery($this->context->method, 'insert', $collection->name, $feature->identifier, $this->context->query, $this->context->getUrl());
        }
        
        return RestoLogUtil::success('Feature ' . $feature->identifier . ' inserted within ' . $collection->name, array(
            'featureIdentifier' => $feature->identifier
        ));
    }
    
    
}
