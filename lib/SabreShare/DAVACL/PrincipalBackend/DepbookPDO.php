<?php
namespace DepbookSabre\DAVACL\PrincipalBackend;
use Sabre\DAVACL\PrincipalBackend as SabrePrincipalBackend;
use Sabre\DAV\Exception as Exception;

class DepbookPDO extends SabrePrincipalBackend\PDO
{
	/**
	 * Get the principal table name.
	 * @return string
	 */
	public function getPrincipalTableName() {
		return $this->tableName;
	}
	
	/**
	 * Get the principal field map
	 * @return array
	 */
	public function getPrincipalFieldMap() {
		return $this->fieldMap;
	}
	
    /**
     * Creates a new  principal.
     *
     * If the creation was a success, an id must be returned
     *
     * @param string $principalUri
     * @param string $name
     * @param array $properties
     * @return string
     */
    public function createPrincipal($principalUri, $name, array $properties) {

        $fieldNames = array(
            'uri'
        );
        $values = array(
            ':principaluri' => $principalUri.'/'.$name
        );

        foreach($this->fieldMap as $xmlName=>$dbName) { 
            if (isset($properties[$xmlName])) {

                $values[':' . $dbName['dbField']] = $properties[$xmlName];
                $fieldNames[] = $dbName['dbField'];
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO ".$this->tableName." (".implode(', ', $fieldNames).") VALUES (".implode(', ',array_keys($values)).")");
        $stmt->execute($values);

        return $this->pdo->lastInsertId();

    }
	
    /**
     * Returns a list of principals based on a prefix.
     *
     * This prefix will often contain something like 'principals'. You are only
     * expected to return principals that are in this base path.
     *
     * You are expected to return at least a 'uri' for every user, you can
     * return any additional properties if you wish so. Common properties are:
     *   {DAV:}displayname
     *   {http://sabredav.org/ns}email-address - This is a custom SabreDAV
     *     field that's actualy injected in a number of other properties. If
     *     you have an email address, use this property.
	 * 
	 * MODIFIED: ADDED SUPPORT FROM AUTOMATIC PROXY PRINCIPALS
     *
     * @param string $prefixPath
     * @return array
     */
    public function getPrincipalsByPrefix($prefixPath) {

        $fields = array(
            'uri',
        );

        foreach($this->fieldMap as $key=>$value) {
            $fields[] = $value['dbField'];
        }
        $result = $this->pdo->query('SELECT '.implode(',', $fields).'  FROM '. $this->tableName);

        $principals = array();

        while($row = $result->fetch(\PDO::FETCH_ASSOC)) {

            // Checking if the principal is in the prefix
            list($rowPrefix) = \Sabre\DAV\URLUtil::splitPath($row['uri']);
            if ($rowPrefix !== $prefixPath) continue;

            $principal = array(
                'uri' => $row['uri'],
            );
            foreach($this->fieldMap as $key=>$value) {
                if ($row[$value['dbField']]) {
                    $principal[$key] = $row[$value['dbField']];
                }
            }
            $principals[] = $principal;
			// add proxies
// 			$principals[] = array('uri'=>$principal['uri'].'/calendar-proxy-read'); 
// 			$principals[] = array('uri'=>$principal['uri'].'/calendar-proxy-write');

        }

        return $principals;

    }

    /**
     * Returns a specific principal, specified by it's path.
     * The returned structure should be the exact same as from
     * getPrincipalsByPrefix.
	 * MODIFIED: ADDED SUPPORT FROM AUTOMATIC PROXY PRINCIPALS
     *
     * @param string $path
     * @return array
     */
    public function getPrincipalByPath($path) {
    	
// 		$path = strpos($path, 'calendar-proxy-read') ? strstr($path, '/calendar-proxy-read', true) : $path;
// 		$path = strpos($path, 'calendar-proxy-write') ? strstr($path, '/calendar-proxy-write', true) : $path;

        $fields = array(
            'id',
            'uri',
        );

        foreach($this->fieldMap as $key=>$value) {
            $fields[] = $value['dbField'];
        }
        $stmt = $this->pdo->prepare('SELECT '.implode(',', $fields).'  FROM '. $this->tableName . ' WHERE uri = ?');
        $stmt->execute(array($path));

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return;

        $principal = array(
            'id'  => $row['id'],
            'uri' => $row['uri'],
        );
        foreach($this->fieldMap as $key=>$value) {
            if ($row[$value['dbField']]) {
                $principal[$key] = $row[$value['dbField']];
            }
        } 
        return $principal;

    }

    /**
     * Returns the list of members for a group-principal
     *
     * @param string $principal
     * @return array
     */
    public function getGroupMemberSet($principal) {

        $principal = $this->getPrincipalByPath($principal);
        if (!$principal) throw new Exception('Principal not found');

        $stmt = $this->pdo->prepare('SELECT principals.uri as uri FROM '.$this->groupMembersTableName.' AS groupmembers LEFT JOIN '.$this->tableName.' AS principals ON groupmembers.member = principals.id WHERE groupmembers.owner = ?');
        $stmt->execute(array($principal['id']));

        $result = array();
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[] = $row['uri'];
        }
        return $result;

    }

    /**
     * Returns the list of groups a principal is a member of
     *
     * @param string $principal
     * @return array
     */
    public function getGroupMembership($principal) {

        $principal = $this->getPrincipalByPath($principal);
        if (!$principal) throw new Exception('Principal not found');

        $stmt = $this->pdo->prepare('SELECT principals.uri as uri, permission FROM '.$this->groupMembersTableName.' AS groupmembers LEFT JOIN '.$this->tableName.' AS principals ON groupmembers.owner = principals.id WHERE groupmembers.member = ?');
        $stmt->execute(array($principal['id']));
 
        $result = array(); 
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        	
// 			if($row['permission'] == 'r') {
// 				$result[] = $row['uri'].'/calendar-proxy-read';
// 			} elseif($row['permission'] == 'r/w') { 
// 				$result[] = $row['uri'].'/calendar-proxy-write';
// 			}
			
        } 
        return $result;

    }

    /**
     * Updates the list of group members for a group principal.
     *
     * The principals should be passed as a list of uri's.
	 * 
	 * MODIFIED: updated to make it work with "virtual" proxy principals
     *
     * @param string $principal
     * @param array $members
     * @return void
     */
    public function setGroupMemberSet($principal, array $members) {
    	
		$permission = '';
// 		if(strpos($principal, 'calendar-proxy-read')) {
// 			$principal = strstr($principal, '/calendar-proxy-read', true);
// 			$permission = 'r';
// 		} else {
// 			$principal = strstr($principal, '/calendar-proxy-write', true);
// 			$permission = 'r/w';
// 		}

        // Grabbing the list of principal id's.
        $stmt = $this->pdo->prepare('SELECT id, uri FROM '.$this->tableName.' WHERE uri IN (? ' . str_repeat(', ? ', count($members)) . ');');
        $stmt->execute(array_merge(array($principal), $members));

        $memberIds = array();
        $principalId = null;

        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) { 
            if ($row['uri'] == $principal) {
                $principalId = $row['id'];
            } else {
                $memberIds[] = $row['id'];
            }
        } 
        if (!$principalId) throw new Exception('Principal not found');

        // Wiping out old members
        $stmt = $this->pdo->prepare('DELETE FROM '.$this->groupMembersTableName.' WHERE owner = ?;');
        $stmt->execute(array($principalId));

        foreach($memberIds as $memberId) {

            $stmt = $this->pdo->prepare('INSERT INTO '.$this->groupMembersTableName.' (owner, member, permission) VALUES (?, ?, ?);');
            $stmt->execute(array($principalId, $memberId, $permission));

        }

    }
    
    /**
     * Retrieves a principal by email address
     *
     * If no match is found NULL is returned.
     * If a match is found then the id for that principal is returned.
     * There shouldn't ever be more than one match, but if there is...
     */
    public function getPrincipalByMailto($string)
    {
    	$email = str_replace("mailto:", "", $string);
    	$query = 'SELECT id FROM ' . $this->getPrincipalTableName() . ' WHERE email LIKE ? ';
    	$stmt = $this->pdo->prepare($query);
    	$stmt->execute(array($email));
    	$result = $stmt->fetch(\PDO::FETCH_ASSOC);
    	if(count($result)==1) {
    		return $result['id'];
    	}
    	else {
    		return null;
    	}
    }
    
    /**
     * Retrieves a principal by id
     */
    public function getPrincipalById($principalId) {
        
    	$fields = array(
    			'id',
    			'uri',
    	);
    
    	foreach($this->fieldMap as $key=>$value) {
    		$fields[] = $value['dbField'];
    	}
    	$stmt = $this->pdo->prepare('SELECT '.implode(',', $fields).'  FROM '. $this->tableName . ' WHERE id = ?');
    	$stmt->execute(array($principalId));
    
    	$row = $stmt->fetch(\PDO::FETCH_ASSOC);
    	if (!$row) return;
    
    	$principal = array(
    			'id'  => $row['id'],
    			'uri' => $row['uri'],
    	);
    	foreach($this->fieldMap as $key=>$value) {
    		if ($row[$value['dbField']]) {
    			$principal[$key] = $row[$value['dbField']];
    		}
    	}
    	return $principal;
    }
}

