<?php
/*
 * @package AJAX_Chat
 * @author Sebastian Tschan
 * @copyright (c) Sebastian Tschan
 * @license Modified MIT License
 * @link https://blueimp.net/ajax/
 * 
 * phpBB3 integration:
 * http://www.phpbb.com/
 */

class CustomAJAXChat extends AJAXChat {

	// Initialize custom configuration settings
	function initCustomConfig() {
		global $db;
		
		// Use the existing phpBB database connection:
		$this->setConfig('dbConnection', 'link', $db->db_connect_id);
	}

	// Initialize custom request variables:
	function initCustomRequestVars() {
		global $user;

		// Auto-login phpBB users:
		if(!$this->getRequestVar('logout') && ($user->data['user_id'] != ANONYMOUS)) {
			$this->setRequestVar('login', true);
		}
	}

	// Replace custom template tags:
	function replaceCustomTemplateTags($tag, $tagContent) {
		global $user;
		
		switch($tag) {

			case 'FORUM_LOGIN_URL':
				if($user->data['is_registered']) {
					return ($this->getRequestVar('view') == 'logs') ? './?view=logs' : './';
				} else {
					return $this->htmlEncode(generate_board_url().'/ucp.php?mode=login');
				}
				
			case 'REDIRECT_URL':
				if($user->data['is_registered']) {
					return '';
				} else {
					return $this->htmlEncode($this->getRequestVar('view') == 'logs' ? $this->getChatURL().'?view=logs' : $this->getChatURL());
				}
			
			default:
				return null;
		}
	}

	// Returns true if the userID of the logged in user is identical to the userID of the authentication system
	// or the user is authenticated as guest in the chat and the authentication system
	function revalidateUserID() {
		global $user;
		
		if($this->getUserRole() === AJAX_CHAT_GUEST && $user->data['user_id'] == ANONYMOUS || ($this->getUserID() === $user->data['user_id'])) {
			return true;
		}
		return false;
	}

	// Returns an associative array containing userName, userID and userRole
	// Returns null if login is invalid
	function getValidLoginUserData() {
		global $auth,$user;
		
		// Return false if given user is a bot:
		if($user->data['is_bot']) {
			return false;
		}
		
		// Check if we have a valid registered user:
		if($user->data['is_registered']) {
			$userData = array();
			$userData['userID'] = $user->data['user_id'];

			$userData['userName'] = $this->trimUserName($user->data['username']);
			
			if($auth->acl_get('a_'))
				$userData['userRole'] = AJAX_CHAT_ADMIN;
			elseif($auth->acl_get('m_'))
				$userData['userRole'] = AJAX_CHAT_MODERATOR;
			else
				$userData['userRole'] = AJAX_CHAT_USER;

			return $userData;
			
		} else {
			// Guest users:
			return $this->getGuestUser();
		}
	}

	// Store the channels the current user has access to
	// Make sure channel names don't contain any whitespace
	function &getChannels() {
		if($this->_channels === null) {
			global $auth;

			$this->_channels = array();

			// Add the valid channels to the channel list (the defaultChannelID is always valid):
			foreach($this->getAllChannels() as $key=>$value) {
				if ($value == $this->getConfig('defaultChannelID')) {
					$this->_channels[$key] = $value;
					continue;
				}
				// Check if we have to limit the available channels:
				if($this->getConfig('limitChannelList') && !in_array($value, $this->getConfig('limitChannelList'))) {
					continue;
				}

				// Add the valid channels to the channel list (the defaultChannelID is always valid):
				if($auth->acl_get('f_read', $value)) {
					$this->_channels[$key] = $value;
				}
			}
		}
		return $this->_channels;
	}

	// Store all existing channels
	// Make sure channel names don't contain any whitespace
	function &getAllChannels() {
		if($this->_allChannels === null) {
			global $db;

			$this->_allChannels = array();


			// Get valid phpBB forums:
			$sql = 'SELECT
							forum_id,
							forum_name
						FROM
							'.FORUMS_TABLE.'
						WHERE
							forum_type=1
						AND
							forum_password=\'\';';
			$result = $db->sql_query($sql);

			$defaultChannelFound = false;

			while ($row = $db->sql_fetchrow($result)) {
				$forumName = $this->trimChannelName($row['forum_name']);

				$this->_allChannels[$forumName] = $row['forum_id'];

				if(!$defaultChannelFound && $row['forum_id'] == $this->getConfig('defaultChannelID')) {
					$defaultChannelFound = true;
				}
			}
			$db->sql_freeresult($result);

			if(!$defaultChannelFound) {
				// Add the default channel as first array element to the channel list
				// First remove it in case it appeard under a different ID
				unset($this->_allChannels[$this->getConfig('defaultChannelName')]);
				$this->_allChannels = array_merge(
					array(
						$this->trimChannelName($this->getConfig('defaultChannelName'))=>$this->getConfig('defaultChannelID')
					),
					$this->_allChannels
				);
			}
		}
		return $this->_allChannels;
	}

	// Method to set the style cookie depending on the phpBB user style
	function setStyle() {
		global $config,$user,$db;
		
		if(isset($_COOKIE[$this->getConfig('sessionName').'_style']) && in_array($_COOKIE[$this->getConfig('sessionName').'_style'], $this->getConfig('styleAvailable')))
			return;
		
		$styleID = (!$config['override_user_style'] && $user->data['user_id'] != ANONYMOUS) ? $user->data['user_style'] : $config['default_style'];
		$sql = 'SELECT
						style_name
					FROM
						'.STYLES_TABLE.'
					WHERE
						style_id = \''.$db->sql_escape($styleID).'\';';
		$result = $db->sql_query($sql);
		$styleName = $db->sql_fetchfield('style_name');
		$db->sql_freeresult($result);
		
		if(!in_array($styleName, $this->getConfig('styleAvailable'))) {
			$styleName = $this->getConfig('styleDefault');
		}
		
		setcookie(
			$this->getConfig('sessionName').'_style',
			$styleName,
			time()+60*60*24*$this->getConfig('sessionCookieLifeTime'),
			$this->getConfig('sessionCookiePath'),
			$this->getConfig('sessionCookieDomain'),
			$this->getConfig('sessionCookieSecure')
		);
		return;
	}
  
  
  function parseCustomCommands($text, $textParts) { 
    switch($textParts[0]) {  
      case '/ligrev':
        if($this->getUserRole() == AJAX_CHAT_ADMIN) {
          if ($this->getChannel() != 18) {
            $text = str_replace('/ligrev', '', $text);
            $this->insertChatBotMessage( $this->getChannel(), $text );  
            return true;
          } else {
            return false;
          }
        } else {
          return false;
        }
        
      case '/away':
        $this->insertChatBotMessage($this->getChannel(), $this->getUserName().' is away.');
        $sql = 'UPDATE '.$this->getDataBaseTable('online').' SET isAway = 1 WHERE userID = '.$this->getUserID().';';
        $result = $this->db->sqlQuery($sql);
        if($result->error()) {
          echo $result->getError();
          die();
        }
        return true;
        
      case '/online':
      case '/here':
      case '/back':
      case '/return':
        $this->insertChatBotMessage($this->getChannel(), $this->getUserName().' has returned.');
        $sql = 'UPDATE '.$this->getDataBaseTable('online').' SET isAway = 0 WHERE userID = '.$this->getUserID().';';
        $result = $this->db->sqlQuery($sql);
        if($result->error()) {
          echo $result->getError();
          die();
        }
        return true;
        
      case '/ping':
        $thetime = time() - $_SERVER['REQUEST_TIME'];
        $this->insertChatBotMessage($this->getChannel(), "Lag: $thetime seconds.");
        return true;
        
      case '/become':
        $text = str_replace('/become', '', $text);
        $this->setUserName($text);
        $this->updateOnlineList();
        $this->addInfoMessage($this->getUserName(), 'userName');
        return true;
        
      case '/slap':
        if ($textParts[1] == '') {
          $textParts[1] = 'Ligrev';
        }
        if ($textParts[2] == '') {
          $textParts[2] = array_rand(array_flip(array('poach', 'salmon', 'greyling', 'coelecanth', 'trout')));
        }
        $this->insertChatBotMessage($this->getChannel(), '[i]'.$this->getUserName().' slaps '.$textParts[1].' with a large '.$textParts[2].'.[/i]');
        return $text;
      case '/cah':
        $this->insertChatBotMessage($this->getPrivateMessageID(), count($textParts));
        return true;
      case '/ofb':
        $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[img]http://24.media.tumblr.com/tumblr_lm9i7aJaR61qjr62mo1_500.jpg[/img]");
        return true;
      case '/noponies':
      $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[img]http://25.media.tumblr.com/tumblr_m1fdwgueip1rs686jo1_400.png[/img]");
        return true;
      case '/punpolice':
      $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[img]http://cdn.calref.net/tyran/images/punpolice.gif[/img]");
        return true;
      case '/yes':
      $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[img]http://cdn.calref.net/sylae/images/misc/yes.png[/img]");
        return true;
      case '/si':
      $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[img]http://cdn.calref.net/files.calref/fed924925590a5199af6507cb1d60d60bac4c8fe.png[/img]");
        return true;
      case '/buzzard':
        $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[img]http://cdn.calref.net/sylae/images/misc/buzzard.png[/img]");
        return true;
      case '/youre':
        $this->insertCustomMessage($this->getUserID(), $this->getUserName(), $this->getUserRole(), $this->getChannel(), "[url=http://calref.net/~sylae/youre.php?word=".$textParts[1]."]*".$textParts[1]."[/url]");
        return true;
      case '/lighack':
      case '/lh':
        return $this->handleLighack($textParts);
      default:  
        return false;  
    }
  }
  function handleLighack($textParts) {
    switch($textParts[1]) {
      case 'character':
        switch($textParts[2]) {
          case 'create':
            // creating new character, first make sure they've provided a name and only a name
            if (count($textParts) != 4) {
              $this->insertChatBotMessage($this->getPrivateMessageID(), "Usage: [code]/lighack character create <name>[/code]");
              break;
            }
            // make sure it isn't taken already
            $sql = 'SELECT * FROM ajax_chat_lighack_characters WHERE `name` = '.$this->db->makeSafe($textParts[3]).';';
            $result = $this->db->sqlQuery($sql);
            if($result->error()) {
              echo $result->getError();
              die();
            }
            if ($result->numRows() != 0) {
              $this->insertChatBotMessage($this->getPrivateMessageID(), "Error: Name already taken.");
              break;
            }
            
            // create the character
            $sql = 'INSERT INTO ajax_chat_lighack_characters (lhcid, name, creator) VALUES (NULL, '.$this->db->makeSafe($textParts[3]).', '.$this->db->makeSafe($this->getUserID()).');';	
            $result = $this->db->sqlQuery($sql);
            if($result->error()) {
              echo $result->getError();
              die();
            }
            $this->insertChatBotMessage($this->getPrivateMessageID(), "Character created.");
            break;
          case 'select':
            // selecting a character, first make sure they've provided a name and only a name
            if (count($textParts) != 4) {
              $this->insertChatBotMessage($this->getPrivateMessageID(), "Usage: [code]/lighack character select <name>[/code]");
              break;
            }
            // make sure the character exists, get the ID if so
            $sql = 'SELECT * FROM ajax_chat_lighack_characters WHERE `name` = '.$this->db->makeSafe($textParts[3]).';';
            $result = $this->db->sqlQuery($sql);
            if($result->error()) {
              echo $result->getError();
              die();
            }
            if ($result->numRows() != 1) {
              $this->insertChatBotMessage($this->getPrivateMessageID(), "Error: Invalid character. Either they don't exist yet, or there's a database issue.");
              break;
            }
            $id = $result->fetch();
            $id = $id['lhcid'];
            
            // remove any existing selection
            $sql = 'DELETE FROM ajax_chat_lighack_users WHERE userID = '.$this->db->makeSafe($this->getUserID()).';';	
            $result = $this->db->sqlQuery($sql);
            if($result->error()) {
              echo $result->getError();
              die();
            }
            // create new selection
            $sql = 'INSERT INTO ajax_chat_lighack_users (userID, lhcid) VALUES ('.$this->db->makeSafe($this->getUserID()).', '.$this->db->makeSafe($id).');';	
            $result = $this->db->sqlQuery($sql);
            if($result->error()) {
              echo $result->getError();
              die();
            }
            
            $this->insertChatBotMessage($this->getPrivateMessageID(), 'Selected lhcid '.$id.' ([i]'.$textParts[3].'[/i]).');
            break;
          default:
            $this->insertChatBotMessage($this->getPrivateMessageID(), "Unknown command, try [code]/lighack help[/code].");
            break;
        }
        break;
      case 'help':
        $this->insertChatBotMessage($this->getPrivateMessageID(), "Unknown command, try [code]/lighack help[/code].");
      default:
        $this->insertChatBotMessage($this->getPrivateMessageID(), "Unknown command, try [code]/lighack help[/code].");
        break;
    }
    return true;
  }
}
?>
