<?php 
namespace SabreShare\CalDAV\Backend;
use Sabre\CalDAV\Backend as SabreBackend;

class SabreSharePDO extends SabreBackend\PDO implements SabreBackend\SharingSupport
{
	/**
	 * The table name that will be used for calendar shares
	 *
	 * @var string
	 */
	protected $calendarSharesTableName;
	
	/**
	 * List of properties for the calendar shares table
	 * This list maps exactly to the field names in the db table
	 */
	public $sharesProperties = array(
			'calendarId',
			'member',
			'status',
			'readOnly',
			'summary',
// 			'displayName',
// 			'colour'
		);
	
	/**
	 * Creates the backend
	 *
	 * @param \PDO $pdo
	 * @param string $calendarTableName
	 * @param string $calendarObjectTableName
	 */
	public function __construct(\PDO $pdo, $calendarTableName = 'calendars', $calendarObjectTableName = 'calendarobjects', $calendarSharesTableName = 'calendarShares') {
	
		parent::__construct($pdo, $calendarTableName, $calendarObjectTableName);
		$this->calendarSharesTableName = $calendarSharesTableName;
	
	}
	
	/**
	 * Updates the list of shares.
	 *
	 * The first array is a list of people that are to be added to the
	 * calendar.
	 *
	 * Every element in the add array has the following properties:
	 *   * href - A url. Usually a mailto: address
	 *   * commonName - Usually a first and last name, or false
	 *   * summary - A description of the share, can also be false
	 *   * readOnly - A boolean value
	 *
	 * Every element in the remove array is just the address string.
	 *
	 * Note that if the calendar is currently marked as 'not shared' by and
	 * this method is called, the calendar should be 'upgraded' to a shared
	 * calendar.
	 *
	 * @param mixed $calendarId
	 * @param array $add
	 * @param array $remove
	 * @return void
	 */
	function updateShares($calendarId, array $add, array $remove)
	{ 
		$fields = array();
		$fields[':calendarId'] = $calendarId; 
		
		// get the principal id based on the mailto address
		// $principal_id = $this->getPrincipalBackend()->getPrincipalByMailto($add[0]['href']);
		// if($principal_id == null) {
			// throw new \Exception("Unknown email address");
		// }
		
		// get the principal path
//		$principalBackend = $this->getPrincipalBackend();
//		$principalPath = $principalBackend->searchPrincipals('principals/users', array('{http://sabredav.org/ns}email-address'=>$add[0]['href']));
//		if($principalPath == 0) {
//			throw new \Exception("Unknown email address");
//		}
//		// use the path to get the principal
//		$principal = $principalBackend->getPrincipalByPath($principalPath);
                
                // get the principal based on the supplied email address
                
                $principal = $this->getPrincipalByEmail($add[0]['href']);
		
		$fields[':member'] = $principal['id'];
		
		$fields[':status'] = \Sabre\CalDAV\SharingPlugin::STATUS_NORESPONSE;
				
		// check we have all the required fields
		foreach($this->sharesProperties as $field) {
			if(isset($add[0][$field])) {
				$fields[':'.$field] = $add[0][$field];
			}
		} 
						
		$stmt = $this->pdo->prepare("INSERT INTO ".$this->calendarSharesTableName." (".implode(', ', $this->sharesProperties).") VALUES (".implode(', ',array_keys($fields)).")");
		
		$stmt->execute($fields);
		
	
// 		are we removing any shares?
		if(count($remove)>0) {
			$r_ids = array();
			foreach($remove as $r_mailto) {
				// get the principalid
                                $r_principal = $this->getPrincipalByEmail($r_mailto);
                                $r_ids[] = $r_principal['id'];
//				$r_principal = $this->getPrinicpalBackend()->getPrincipalByMailto($r_mailto);
//				$r_ids[] = $r_principal;
			}	
			$stmt = $this->pdo->prepare("DELETE FROM ".$this->calendarSharesTableName." WHERE MEMBER = ?");
			$stmt->execute($r_ids);
		}
	}
	
	/**
	 * Returns the list of people whom this calendar is shared with.
	 *
	 * Every element in this array should have the following properties:
	 *   * href - Often a mailto: address
	 *   * commonName - Optional, for example a first + last name
	 *   * status - See the Sabre\CalDAV\SharingPlugin::STATUS_ constants.
	 *   * readOnly - boolean
	 *   * summary - Optional, a description for the share
	 *
	 * @return array
	 */
	public function getShares($calendarId) {
		
		$fields = implode(', ', $this->sharesProperties);
		$stmt = $this->pdo->prepare("SELECT " . $fields . " FROM ".$this->calendarSharesTableName." WHERE calendarId = ? ORDER BY calendarId ASC");
		$stmt->execute(array($calendarId));

		$shares = array();
		while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) { 
			$share = array(	'calendarId'=>$row['calendarId'],
							'readonly'=>$row['readOnly'],
							'summary'=>$row['summary'],
// 							'displayName'=>$row['displayName'],
// 							'colour'=>$row['colour']
					);
			
			// map the status integer to a predefined constant
			switch($row['status']) {
				case 1: 
					$share['status'] = 'STATUS_ACCEPTED'; 
					break;
				case 2: 
					$share['status'] = 'STATUS_DECLINED'; 
					break;
				case 3: 
					$share['status'] = 'STATUS_DELETED'; 
					break;
				case 4: 
					$share['status'] = 'STATUS_NORESPONSE'; 
					break;
				case 5: 
					$share['status'] = 'STATUS_INVALID'; 
					break;
			}
			
			// get the member principal
			$memberPrincipal = $this->getPrincipalBackend()->getPrincipalById($row['member']);
			$share['href'] = $memberPrincipal['{http://sabredav.org/ns}email-address'];
			$share['commonName'] = $memberPrincipal['{DAV:}displayname'];
			
			// add it to main array
			$shares[] = $share;
		}
	
		return $shares;
	}
	
	/**
	 * Returns a list of calendars for a principal.
	 *
	 * Every project is an array with the following keys:
	 *  * id, a unique id that will be used by other functions to modify the
	 *    calendar. This can be the same as the uri or a database key.
	 *  * uri, which the basename of the uri with which the calendar is
	 *    accessed.
	 *  * principaluri. The owner of the calendar. Almost always the same as
	 *    principalUri passed to this method.
	 *
	 * Furthermore it can contain webdav properties in clark notation. A very
	 * common one is '{DAV:}displayname'.
	 * 
	 * MODIFIED: THIS METHOD NOW NEEDS TO BE ABLE TO RETRIEVE SHARED CALENDARS
	 *
	 * @param string $principalUri
	 * @return array
	 */
	public function getCalendarsForUser($principalUri) {
	
		$fields = array_values($this->propertyMap);
		$fields[] = 'id';
		$fields[] = 'uri';
		$fields[] = 'ctag';
		$fields[] = 'components';
		$fields[] = 'principaluri';
		$fields[] = 'transparent';
	
		// Making fields a comma-delimited list
		$fields = implode(', ', $fields);
		$stmt = $this->pdo->prepare("SELECT " . $fields . " FROM ".$this->calendarTableName." WHERE principaluri = ? ORDER BY calendarorder ASC");
		$stmt->execute(array($principalUri));
	
		$calendars = array();
		while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
	
			$components = array();
			if ($row['components']) {
				$components = explode(',',$row['components']);
			}
	
			$calendar = array(
					'id' => $row['id'],
					'uri' => $row['uri'],
					'principaluri' => $row['principaluri'],
					'{' . \Sabre\CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => $row['ctag']?$row['ctag']:'0',
					'{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new \Sabre\CalDAV\Property\SupportedCalendarComponentSet($components),
					'{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new \Sabre\CalDAV\Property\ScheduleCalendarTransp($row['transparent']?'transparent':'opaque'),
			);
	
	
			foreach($this->propertyMap as $xmlName=>$dbName) {
				$calendar[$xmlName] = $row[$dbName];
			}
	
			$calendars[] = $calendar;
	
		}
		
		// now let's get any shared calendars
		$shareFields = implode(', ', $this->sharesProperties);
		
		// get the principal id
		$principalBackend = $this->getPrincipalBackend();
		$principal = $principalBackend->getPrincipalByPath($principalUri);
		
		$shareStmt = $this->pdo->prepare("SELECT ". $shareFields . " FROM ".$this->calendarSharesTableName." WHERE member = ?");
		$shareStmt->execute(array($principal['id']));
		while($shareRow = $shareStmt->fetch(\PDO::FETCH_ASSOC)) {
			// get the original calendar
			$calStmt = $this->pdo->prepare("SELECT " . $fields . " FROM ".$this->calendarTableName." WHERE id = ? ORDER BY calendarorder ASC LIMIT 1");
			$calStmt->execute(array($shareRow['calendarId']));
			
			while($calendarShareRow = $calStmt->fetch(\PDO::FETCH_ASSOC)) {
				
				$shareComponents = array();
				if ($calendarShareRow['components']) {
					$shareComponents = explode(',',$calendarShareRow['components']);
				}
				
				$sharedCalendar = array(
						'id' => $calendarShareRow['id'],
						'uri' => $calendarShareRow['uri'],
						'principaluri' => $principalUri,
						'{' . \Sabre\CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => $calendarShareRow['ctag']?$calendarShareRow['ctag']:'0',
						'{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new \Sabre\CalDAV\Property\SupportedCalendarComponentSet($shareComponents),
						'{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new \Sabre\CalDAV\Property\ScheduleCalendarTransp($calendarShareRow['transparent']?'transparent':'opaque'),
				);
				// some specific properies for shared calendars
				$sharedCalendar['{http://calendarserver.org/ns/}shared-url'] = $calendarShareRow['uri'];
				$sharedCalendar['{http://sabredav.org/ns}owner-principal'] = $calendarShareRow['principaluri'];
				$sharedCalendar['{http://sabredav.org/ns}read-only'] = $shareRow['readOnly'];
				$sharedCalendar['{http://calendarserver.org/ns/}summary'] = $shareRow['summary'];
				
				foreach($this->propertyMap as $xmlName=>$dbName) {
					
// 					if($xmlName == '{DAV:}displayname') { 
// 						$sharedCalendar[$xmlName] = $shareRow['displayname'] == null ? $calendarShareRow['displayName'] : $shareRow['displayname'];
// 					} elseif($xmlName == '{http://apple.com/ns/ical/}calendar-color') {
// 						$sharedCalendar[$xmlName] = $shareRow['colour'] == null ? $calendarShareRow['calendarcolor'] : $shareRow['colour'];
// 					} else {
						$sharedCalendar[$xmlName] = $calendarShareRow[$dbName];
// 					}
				}
				
				$calendars[] = $sharedCalendar;
				
			}
		}
		
		return $calendars;
	
	}
	
	/**
	 * This method is called when a user replied to a request to share.
	 *
	 * If the user chose to accept the share, this method should return the
	 * newly created calendar url.
	 *
	 * @param string href The sharee who is replying (often a mailto: address)
	 * @param int status One of the SharingPlugin::STATUS_* constants
	 * @param string $calendarUri The url to the calendar thats being shared
	 * @param string $inReplyTo The unique id this message is a response to
	 * @param string $summary A description of the reply
	 * @return null|string
	 */
	function shareReply($href, $status, $calendarUri, $inReplyTo, $summary = null){ }
	
	/**
	 * Marks this calendar as published.
	 *
	 * Publishing a calendar should automatically create a read-only, public,
	 * subscribable calendar.
	 *
	 * @param bool $value
	 * @return void
	 */
	public function setPublishStatus($calendarId, $value) {
	
	
	}
	
	/**
	 * Returns a list of notifications for a given principal url.
	 *
	 * The returned array should only consist of implementations of
	 * \Sabre\CalDAV\Notifications\INotificationType.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	public function getNotificationsForPrincipal($principalUri){ }
	
	/**
	 * This deletes a specific notifcation.
	 *
	 * This may be called by a client once it deems a notification handled.
	 *
	 * @param string $principalUri
	 * @param \Sabre\CalDAV\Notifications\INotificationType $notification
	 * @return void
	 */
	public function deleteNotification($principalUri, \Sabre\CalDAV\Notifications\INotificationType $notification){ }

	private function getPrincipalByEmail($email) {
            
            $principalBackend = $this->getPrincipalBackend();
            $principalPath = $principalBackend->searchPrincipals('principals/users', array('{http://sabredav.org/ns}email-address'=>$email));
            if($principalPath == 0) {
			throw new \Exception("Unknown email address");
            }
		// use the path to get the principal
            return $principalBackend->getPrincipalByPath($principalPath);
        }
        
        
	private function getPrincipalBackend() {
		return new \Sabre\DAVACL\PrincipalBackend\PDO($this->pdo);
	}
}

?>