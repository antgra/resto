<?php

/*
 * RESTo
 * 
 * RESTo - REstful Semantic search Tool for geOspatial 
 * 
 * Copyright 2013 Jérôme Gasperi <https://github.com/jjrom>
 * 
 * jerome[dot]gasperi[at]gmail[dot]com
 * 
 * 
 * This software is governed by the CeCILL-B license under French law and
 * abiding by the rules of distribution of free software.  You can  use,
 * modify and/ or redistribute the software under the terms of the CeCILL-B
 * license as circulated by CEA, CNRS and INRIA at the following URL
 * "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and  rights to copy,
 * modify and redistribute granted by the license, users are provided only
 * with a limited warranty  and the software's author,  the holder of the
 * economic rights,  and the successive licensors  have only  limited
 * liability.
 *
 * In this respect, the user's attention is drawn to the risks associated
 * with loading,  using,  modifying and/or developing or reproducing the
 * software by the user in light of its specific status of free software,
 * that may mean  that it is complicated to manipulate,  and  that  also
 * therefore means  that it is reserved for developers  and  experienced
 * professionals having in-depth computer knowledge. Users are therefore
 * encouraged to load and test the software's suitability as regards their
 * requirements in conditions enabling the security of their systems and/or
 * data to be ensured and,  more generally, to use and operate it in the
 * same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL-B license and that you accept its terms.
 * 
 */

/**
 * RESTo PostgreSQL users functions
 */
class Functions_users {
    
    private $dbDriver = null;
    private $dbh = null;
    
    /**
     * Constructor
     * 
     * @param array $config
     * @param RestoCache $cache
     * @throws Exception
     */
    public function __construct($dbDriver) {
        $this->dbDriver = $dbDriver;
        $this->dbh = $dbDriver->dbh();
    }
    
    /**
     * Return encrypted user password
     * 
     * @param string $identifier : email
     * 
     * @throws Exception
     */
    public function getUserPassword($identifier) {
        $query = 'SELECT password FROM usermanagement.users WHERE email=\'' . pg_escape_string($identifier) . '\'';
        $results = $this->dbDriver->fetch($this->dbDriver->query($query));
        return count($results) === 1 ? $results[0]['password'] : null;
    }
        
    /**
     * Get user profile
     * 
     * @param string $identifier : can be email (or string) or integer (i.e. uid)
     * @param string $password : if set then profile is returned only if password is valid
     * @return array : this function should return array('userid' => -1, 'groupname' => 'unregistered')
     *                 if user is not found in database
     * @throws exception
     */
    public function getUserProfile($identifier, $password = null) {
        
        /*
         * Default profile - for unregistered users
         */
        $profile = array(
            'userid' => -1,
            'groupname' => 'unregistered'
        );
        
        /*
         * Unregistered users
         */
        if (!isset($identifier) || !$identifier || $identifier === 'unregistered') {
            return $profile;
        }
        
        $query = 'SELECT userid, email, md5(email) as userhash, groupname, username, givenname, lastname, to_char(registrationdate, \'YYYY-MM-DD"T"HH24:MI:SS"Z"\'), activated, connected FROM usermanagement.users WHERE ' . $this->useridOrEmailFilter($identifier) . (isset($password) ? ' AND password=\'' . pg_escape_string(sha1($password)). '\'' : '');
        $results = $this->dbDriver->fetch($this->dbDriver->query($query));
        
        return count($results) === 1 ? $results[0] : $profile;
        
    }

    /**
     * Check if user identified by $identifier exists within database
     * 
     * @param string $email - user email
     * 
     * @return boolean
     * @throws Exception
     */
    public function userExists($email) {
        $query = 'SELECT 1 FROM usermanagement.users WHERE email=\'' . pg_escape_string($email) . '\'';
        return !empty($this->dbDriver->fetch($this->dbDriver->query($query)));
    }
    
    /**
     * Return true if $userid is connected
     * 
     * @param string $identifier : userid or email
     * 
     * @throws Exception
     */
    public function userIsConnected($identifier) {
        if (!isset($identifier)) {
            return false;
        }
        $query = 'SELECT 1 FROM usermanagement.users WHERE ' . $this->useridOrEmailFilter($identifier) . ' AND connected=1';
        return !empty($this->dbDriver->fetch($this->dbDriver->query(($query))));
    }
    
    /**
     * Save user profile to database i.e. create new entry if user does not exist
     * 
     * @param array $profile
     * @return array (userid, activationcode)
     * @throws exception
     */
    public function storeUserProfile($profile) {
       
        if (!is_array($profile) || !isset($profile['email'])) {
            RestoLogUtil::httpError(500, 'Cannot save user profile - invalid user identifier');
        }
        if ($this->userExists($profile['email'])) {
            RestoLogUtil::httpError(500, 'Cannot save user profile - user already exist');
        }
        $email = trim(strtolower($profile['email']));
        $values = array(
            '\'' . pg_escape_string($email) . '\'',
            '\'' . (isset($profile['password']) ? sha1($profile['password']) : str_repeat('*', 40)) . '\'',
            isset($profile['groupname']) ? '\'' . pg_escape_string($profile['groupname']) . '\'' : '\'default\'',
            isset($profile['username']) ? '\'' . pg_escape_string($profile['username']) . '\'' : 'NULL',
            isset($profile['givenname']) ? '\'' . pg_escape_string($profile['givenname']) . '\'' : 'NULL',
            isset($profile['lastname']) ? '\'' . pg_escape_string($profile['lastname']) . '\'' : 'NULL',
            '\'' . pg_escape_string(sha1($email . microtime())) . '\'',
            $profile['activated'],
            'now()'
        );
        // TODO change to pg_fetch_assoc ?
        $results = $this->dbDriver->query('INSERT INTO usermanagement.users (email,password,groupname,username,givenname,lastname,activationcode,activated,registrationdate) VALUES (' . join(',', $values) . ') RETURNING userid, activationcode');
        return pg_fetch_array($results);
        
    }
    
    /**
     * Update user profile to database
     * 
     * @param array $profile
     * @return integer (userid)
     * @throws exception
     */
    public function updateUserProfile($profile) {
       
        if (!is_array($profile) || !isset($profile['email'])) {
            RestoLogUtil::httpError(500, 'Cannot update user profile - invalid user identifier');
        }

        /*
         * Only password, groupname, activated and connected fields can be updated
         */
        $values = array();
        if (isset($profile['password'])) {
            $values[] = 'password=\'' . sha1($profile['password']) . '\'';
        }
        if (isset($profile['groupname'])) {
            $values[] = 'groupname=\'' . pg_escape_string($profile['groupname']) . '\'';
        }
        if (isset($profile['activated'])) {
            $values[] = 'activated=' . $profile['activated'];
        }
        if (isset($profile['connected'])) {
            $values[] = 'connected=' . $profile['connected'];
        }
        
        $results = $this->dbDriver->fetch($this->dbDriver->query('UPDATE usermanagement.users SET ' . join(',', $values) . ' WHERE email=\'' . pg_escape_string(trim(strtolower($profile['email']))) .'\' RETURNING userid'));
        
        return count($results) === 1 ? $results[0]['userid'] : null;
        
    }
    
    /**
     * Disconnect user
     * 
     * @param string $email
     */
    public function disconnectUser($email) {
        $query = 'UPDATE usermanagement.users SET connected=FALSE WHERE email=\'' . pg_escape_string($email) . '\'';
        $this->dbDriver->query($query);
        return true;
    }

    /**
     * Check if user signed collection license
     * 
     * @param string $identifier
     * @param string $collectionName
     * 
     * @return boolean
     */
    public function isLicenseSigned($identifier, $collectionName) {
        $query = 'SELECT 1 FROM usermanagement.signatures WHERE email= \'' . pg_escape_string($identifier) . '\' AND collection= \'' . pg_escape_string($collectionName) . '\'';
        return !empty($this->dbDriver->fetch($this->dbDriver->query(($query))));
    }
    
    /**
     * Sign license for collection collectionName
     * 
     * @param string $identifier : user identifier 
     * @param string $collectionName
     * @return boolean
     * @throws Exception
     */
    public function signLicense($identifier, $collectionName) {
        
        if (!$this->collectionExists($collectionName)) {
            RestoLogUtil::httpError(500, 'Cannot sign license');
        }
        $results = $this->dbDriver->query('SELECT email FROM usermanagement.signatures WHERE email=\'' . pg_escape_string($identifier) . '\' AND collection=\'' . pg_escape_string($collectionName) . '\'');
        if (pg_fetch_assoc($results)) {
            $this->dbDriver->query('UPDATE usermanagement.signatures SET signdate=now() WHERE email=\'' . pg_escape_string($identifier) . '\' AND collection=\'' . pg_escape_string($collectionName) . '\'');
        }
        else {
            $this->dbDriver->query('INSERT INTO usermanagement.signatures (email, collection, signdate) VALUES (\'' . pg_escape_string($identifier) . '\',\'' . pg_escape_string($collectionName) . '\',now())');
        }
        return true;
    }
    
    /**
     * Get user history
     * 
     * @param integer $userid
     * @param array $options
     *          
     *      array(
     *         'orderBy' => // order field (default querytime),
     *         'ascOrDesc' => // ASC or DESC (default DESC)
     *         'collectionName' => // collection name
     *         'service' => // 'search', 'download' or 'visualize' (default null),
     *         'startIndex' => // (default 0),
     *         'numberOfResults' => // (default 50)
     *     )
     *          
     * @return array
     * @throws Exception
     */
    public function getHistory($userid = null, $options = array()) {
        
        $result = array();
        
        $orderBy = isset($options['orderBy']) ? $options['orderBy'] : 'querytime';
        $ascOrDesc = isset($options['ascOrDesc']) ? $options['ascOrDesc'] : 'DESC';
        $startIndex = isset($options['startIndex']) ? $options['startIndex'] : 0;
        $numberOfResults = isset($options['numberOfResults']) ? $options['numberOfResults'] : 50;
        
        $where = array();
        if (isset($userid)) {
            $where[] = 'userid=' . pg_escape_string($userid);
        }
        if (isset($options['service'])) {
            $where[] = 'service=\'' . pg_escape_string($options['service']) . '\'';
        }
        if (isset($options['collectionName'])) {
            $where[] = 'collection=\'' . pg_escape_string($options['collectionName']) . '\'';
        }

        $results = $this->dbDriver->query('SELECT gid, userid, method, service, collection, resourceid, query, querytime, url, ip FROM usermanagement.history' . (count($where) > 0 ? ' WHERE ' . join(' AND ', $where) : '') . ' ORDER BY ' . pg_escape_string($orderBy) . ' ' . pg_escape_string($ascOrDesc) . ' LIMIT ' . $numberOfResults . ' OFFSET ' . $startIndex, 500, 'Cannot get history');
        while ($row = pg_fetch_assoc($results)) {
            $result[$row['gid']] = $row;
        }
        return $result;
        
    }
    
    /**
     * Activate user
     * 
     * @param string $userid : can be userid or base64(email)
     * @param string $activationcode
     * 
     * @throws Exception
     */
    public function activateUser($userid, $activationcode = null) {
        $query = 'UPDATE usermanagement.users SET activated=1 WHERE userid=\'' . pg_escape_string($userid) . '\'' . (isset($activationcode) ? ' AND activationcode=\'' . pg_escape_string($activationcode) . '\'' :'') . ' RETURNING userid';
        $results = $this->dbDriver->fetch($this->dbDriver->query($query));
        if (count($results) === 1) {
            return true;
        }
        return false;
    }
    
    /**
     * Deactivate user
     * 
     * @param string $userid
     * @throws Exception
     */
    public function deactivateUser($userid) {
        $query = 'UPDATE usermanagement.users SET activated=0 WHERE userid=\'' . pg_escape_string($userid) . '\'';
        $results = $this->dbDriver->fetch($this->dbDriver->query($query));
        if (count($results) === 1) {
            return true;
        }
        return false;
    }
    
    /**
     * Return filter on user
     * 
     * @param string $identifier
     */
    private function useridOrEmailFilter($identifier) {
        return ctype_digit($identifier) ? 'userid=' . $identifier : 'email=\'' . pg_escape_string($identifier) . '\'';
    }
    
}
