<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2009-11 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version. 
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
use Xibo\Helper\Log;


class UserGroup extends Data
{
    public function GetPermissionsForObject($object, $idCol, $objectId, $clause = '') 
    {
        try {
            $dbh = \Xibo\Storage\PDOConnect::init();

            $params = array('id' => $objectId);
            $SQL  = 'SELECT joinedGroup.groupid, joinedGroup.group, view, edit, del, joinedGroup.isuserspecific ';
            $SQL .= '  FROM (
                    SELECT `group`.*
                      FROM `group`
                     WHERE IsUserSpecific = 0
                    UNION ALL
                    SELECT `group`.*
                      FROM `group`
                        INNER JOIN lkusergroup
                        ON lkusergroup.GroupID = group.GroupID
                            AND IsUserSpecific = 1
                        INNER JOIN `user`
                        ON lkusergroup.UserID = user.UserID
                            AND retired = 0
                ) joinedGroup ';
            $SQL .= '   LEFT OUTER JOIN ' . $object;
            $SQL .= '   ON ' . $object . '.GroupID = joinedGroup.GroupID ';

            if ($clause != '') {
                $SQL .= $clause;
            }
            else {
                $SQL .= '       AND ' . $object . '.' . $idCol . ' = :id ';
                $params = array('id' => $objectId);
            }

            $SQL .= 'ORDER BY joinedGroup.IsEveryone DESC, joinedGroup.IsUserSpecific, joinedGroup.`Group`; ';

            Log::sql($SQL, $params);
        
            $sth = $dbh->prepare($SQL);
            $sth->execute($params);

            return $sth->fetchAll();
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                $this->SetError(1, __('Unknown Error'));
        
            return false;
        }
    }

    /**
     * Adds a User Group to Xibo
     * @return
     * @param $UserGroup Object
     * @param $isDisplaySpecific Object
     * @param $description Object[optional]
     */
    public function Add($group, $isUserSpecific)
    {
        Log::notice('IN', 'UserGroup', 'Add');
        
        try {
            $dbh = \Xibo\Storage\PDOConnect::init();
        
            // Validation
            if ($group == '')
                $this->ThrowError(__('Group Name cannot be empty.'));

            $sth = $dbh->prepare('INSERT INTO `group` (`group`, IsUserSpecific) VALUES (:group, :isuserspecific)');
            $sth->execute(array(
                    'group' => $group,
                    'isuserspecific' => $isUserSpecific
                ));

            $groupID = $dbh->lastInsertId();
    
            Log::notice('OUT', 'UserGroup', 'Add');
    
            return $groupID;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                return $this->SetError(25000, __('Could not add User Group'));
        
            return false;
        }
    }

    /**
     * Edits an existing Xibo Display Group
     * @return
     * @param $userGroupID Object
     * @param $UserGroup Object
     */
    public function Edit($userGroupID, $userGroup)
    {
        Log::notice('IN', 'UserGroup', 'Edit');

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();

            // Validation
            if ($userGroupID == 0)
                $this->ThrowError(__('User Group not selected'));
            
            if ($userGroup == '')
                $this->ThrowError(__('User Group Name cannot be empty.'));
        
            $sth = $dbh->prepare('UPDATE `group` SET `group` = :group WHERE groupid = :groupid');
            $sth->execute(array(
                    'group' => $userGroup,
                    'groupid' => $userGroupID
                ));
    
            Log::notice('OUT', 'UserGroup', 'Edit');
    
            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                return $this->SetError(25005, __('Could not edit User Group'));
        
            return false;
        }
    }

    /**
     * Deletes an Xibo User Group
     * @param int $userGroupId
     * @return bool
     */
    public function Delete($userGroupId)
    {
        Log::debug('IN: ' . $userGroupId);

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();
            
            $params = array('groupid' => $userGroupId);

            // Delete all permissions
            $sth = $dbh->prepare('DELETE FROM `lkcampaigngroup` WHERE GroupID = :groupid');
            $sth->execute($params);
            $sth = $dbh->prepare('DELETE FROM `lkdatasetgroup` WHERE GroupID = :groupid');
            $sth->execute($params);
            $sth = $dbh->prepare('DELETE FROM `lkdisplaygroupgroup` WHERE GroupID = :groupid');
            $sth->execute($params);
            $sth = $dbh->prepare('DELETE FROM `lklayoutmediagroup` WHERE GroupID = :groupid');
            $sth->execute($params);
            $sth = $dbh->prepare('DELETE FROM `lklayoutregiongroup` WHERE GroupID = :groupid');
            $sth->execute($params);
            $sth = $dbh->prepare('DELETE FROM `lkmediagroup` WHERE GroupID = :groupid');
            $sth->execute($params);

            // Remove linked users
            $sth = $dbh->prepare('DELETE FROM `lkusergroup` WHERE GroupID = :groupid');
            $sth->execute($params);

            // Delete all menu links
            $sth = $dbh->prepare('DELETE FROM `lkmenuitemgroup` WHERE GroupID = :groupid');
            $sth->execute($params);

            // Delete all page links
            $sth = $dbh->prepare('DELETE FROM `lkpagegroup` WHERE GroupID = :groupid');
            $sth->execute($params);

            // Delete the user group
            $sth = $dbh->prepare('DELETE FROM `group` WHERE GroupID = :groupid');
            $sth->execute($params);
    
            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                return $this->SetError(25015,__('Unable to delete User Group.'));
        
            return false;
        }
    }

    /**
     * Links a User to a User Group
     * @return
     * @param $userGroupID Object
     * @param $userID Object
     */
    public function Link($userGroupID, $userID)
    {
        Log::notice('IN', 'UserGroup', 'Link');

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();
        
            $sth = $dbh->prepare('INSERT INTO   lkusergroup (GroupID, UserID) VALUES (:groupid, :userid)');
            $sth->execute(array(
                    'groupid' => $userGroupID,
                    'userid' => $userID
                ));

            Log::notice('OUT', 'UserGroup', 'Link');
    
            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                $this->SetError(25005, __('Could not Link User Group to User'));
        
            return false;
        }
    }

    /**
     * Unlinks a Display from a Display Group
     * @return
     * @param $userGroupID Object
     * @param $userID Object
     */
    public function Unlink($userGroupID, $userID)
    {
        Log::notice('IN', 'UserGroup', 'Unlink');
        
        try {
            $dbh = \Xibo\Storage\PDOConnect::init();
        
            $sth = $dbh->prepare('DELETE FROM lkusergroup WHERE GroupID = :groupid AND UserID = :userid');
            $sth->execute(array(
                    'groupid' => $userGroupID,
                    'userid' => $userID
                ));
        
            Log::notice('OUT', 'UserGroup', 'Unlink');
    
            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                $this->SetError(25007, __('Could not Unlink User from User Group'));
        
            return false;
        }
    }

    /**
     * Unlinks all users from the speficied group
     * @param <type> $userGroupId
     */
    public function UnlinkAllUsers($userGroupId)
    {
        Log::notice('IN', 'UserGroup', 'UnlinkAllUsers');

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();
        
            $sth = $dbh->prepare('DELETE FROM lkusergroup WHERE GroupID = :groupid');
            $sth->execute(array(
                    'groupid' => $userGroupID
                ));

            Log::notice('OUT', 'UserGroup', 'UnlinkAllUsers');
    
            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                $this->SetError(25007, __('Could not Unlink all Users from User Group'));
        
            return false;
        }
    }

    /**
     * Unliks all groups from the specified user
     * @param <type> $userId
     */
    public function UnlinkAllGroups($userId)
    {
        Log::notice('IN', 'UserGroup', 'UnlinkAllGroups');

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();
        
            $sth = $dbh->prepare('DELETE FROM lkusergroup WHERE UserID = :userid');
            $sth->execute(array(
                    'userid' => $userId
                ));

            Log::notice('OUT', 'UserGroup', 'UnlinkAllGroups');

            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                $this->SetError(25007, __('Could not Unlink Groups from User'));
        
            return false;
        }
    }

    /**
     * Edits the User Group associated with a User
     * @return
     * @param $userID Object
     * @param $userName Object
     */
    public function EditUserGroup($userID, $userName)
    {
        Log::notice('IN', 'UserGroup', 'EditUserGroup');

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();

            // Get the UserGroupID for this UserID
            $SQL  = "SELECT `group`.GroupID ";
            $SQL .= "FROM   `group` ";
            $SQL .= "       INNER JOIN lkusergroup ";
            $SQL .= "       ON     lkusergroup.GroupID = `group`.groupID ";
            $SQL .= "WHERE  `group`.IsUserSpecific     = 1 ";
            $SQL .= "   AND lkusergroup.UserID = :userid";


            $sth = $dbh->prepare($SQL);
            $sth->execute(array(
                   'userid'  => $userID
                ));

            if (!$row = $sth->fetch())
                $this->ThrowError(25005, __('Unable to get the UserGroup for this User.'));
    
            $userGroupID = \Xibo\Helper\Sanitize::int($row['GroupID']);
    
            if ($userGroupID == 0)
            {
                // We should always have 1 display specific UserGroup for a display.
                // Do we a) Error here and give up?
                //         b) Create one and link it up?
                // $this->SetError(25006, __('Unable to get the UserGroup for this Display'));
    
                if (!$userGroupID = $this->Add($userName, 1))
                    $this->ThrowError(25001, __('Could not add a user group for this user.'));
    
                // Link the Two together
                if (!$this->Link($userGroupID, $userID))
                    $this->ThrowError(25001, __('Could not link the new user with its group.'));
            }
            else
            {
                if (!$this->Edit($userGroupID, $userName)) 
                    throw new Exception("Error Processing Request", 1);
            }
            
            Log::notice('OUT', 'UserGroup', 'EditUserGroup');
    
            return true;  
        }
        catch (Exception $e) {
            
            Log::error($e->getMessage());
        
            if (!$this->IsError())
                $this->SetError(1, __('Unknown Error'));
        
            return false;
        }
    }

    /**
     * Returns an array containing the type of children owned by the group
     * @param int $groupId
     * @return array[string]
     * @throws Exception
     */
    public function getChildTypes($groupId)
    {
        try {
            $types = array();

            if (PDOConnect::exists('SELECT GroupID FROM lkdatasetgroup WHERE GroupID = :groupId', array('groupId' => $groupId)))
                $types[] = 'data sets';

            if (PDOConnect::exists('SELECT GroupID FROM lkdisplaygroupgroup WHERE GroupID = :groupId', array('groupId' => $groupId)))
                $types[] = 'display groups';

            if (PDOConnect::exists('SELECT GroupID FROM lkcampaigngroup WHERE GroupID = :groupId', array('groupId' => $groupId)))
                $types[] = 'layouts and campaigns';

            if (PDOConnect::exists('SELECT GroupID FROM lklayoutmediagroup WHERE GroupID = :groupId', array('groupId' => $groupId)))
                $types[] = 'media on layouts';

            if (PDOConnect::exists('SELECT GroupID FROM lklayoutregiongroup WHERE GroupID = :groupId', array('groupId' => $groupId)))
                $types[] = 'regions on layouts';

            if (PDOConnect::exists('SELECT GroupID FROM lkmediagroup WHERE GroupID = :groupId', array('groupId' => $groupId)))
                $types[] = 'media';

            return $types;
        }
        catch (Exception $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }
}
