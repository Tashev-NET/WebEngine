<?php
/**
 * WebEngine CMS
 * https://webenginecms.org/
 * 
 * @version 1.2.1
 * @author Lautaro Angelico <http://lautaroangelico.com/>
 * @copyright (c) 2013-2020 Lautaro Angelico, All Rights Reserved
 * 
 * Licensed under the MIT license
 * http://opensource.org/licenses/MIT
 */

class Character {
	
	protected $_classData;
	
	protected $_userid;
	protected $_username;
	protected $_character;
	
	protected $_unstickMap = 0;
	protected $_unstickCoordX = 125;
	protected $_unstickCoordY = 125;
	
	protected $_clearPkLevel = 3;
	
	function __construct() {
		
		// load databases
		$this->muonline = Connection::Database('MuOnline');
		
		// common
		$this->common = new common();
		
		// class data
		$classData = custom('character_class');
		if(!is_array($classData)) throw new Exception(lang('error_108'));
		$this->_classData = $classData;
		
	}
	
	public function setUserid($userid) {
		if(!Validator::UnsignedNumber($userid)) throw new Exception(lang('error_111'));
		$this->_userid = $userid;
	}
	
	public function setUsername($username) {
		if(!Validator::UsernameLength($username)) throw new Exception(lang('error_112'));
		$this->_username = $username;
	}
	
	public function setCharacter($character) {
		$this->_character = $character;
	}

	public function CharacterReset($username,$character_name,$userid) {
		try {
			if(!check_value($username)) throw new Exception(lang('error_23',true));
			if(!check_value($character_name)) throw new Exception(lang('error_23',true));
			if(!Validator::Number($userid)) throw new Exception(lang('error_23',true));
			if(!Validator::UsernameLength($username)) throw new Exception(lang('error_23',true));
			if(!Validator::AlphaNumeric($username)) throw new Exception(lang('error_23',true));
			if(!$this->CharacterExists($character_name)) throw new Exception(lang('error_32',true));
			if(!$this->CharacterBelongsToAccount($character_name,$username)) throw new Exception(lang('error_32',true));
			if($this->common->accountOnline($username)) throw new Exception(lang('error_14',true));
			
			$characterData = $this->CharacterData($character_name);
			if($characterData[_CLMN_CHR_LVL_] < mconfig('resets_required_level')) throw new Exception(lang('error_33',true));
			
			if(mconfig('resets_enable_zen_requirement')) {
				if($characterData[_CLMN_CHR_ZEN_] < mconfig('resets_price_zen')) throw new Exception(lang('error_34',true));
				$deductZen = $this->DeductZEN($character_name, mconfig('resets_price_zen'));
				if(!$deductZen) throw new Exception(lang('error_34',true));
			}
			
			$update = $this->muonline->query("UPDATE "._TBL_CHR_." SET "._CLMN_CHR_LVL_." = 1,"._CLMN_CHR_RSTS_." = "._CLMN_CHR_RSTS_." + 1 WHERE "._CLMN_CHR_NAME_." = ?", array($character_name));
			if(!$update) throw new Exception(lang('error_23',true));
			
			// SUCCESS
			message('success', lang('success_8',true));
			
			if(mconfig('resets_enable_credit_reward')) {
				try {
					$creditSystem = new CreditSystem();
					$creditSystem->setConfigId(mconfig('credit_config'));
					$configSettings = $creditSystem->showConfigs(true);
					switch($configSettings['config_user_col_id']) {
						case 'userid':
							$creditSystem->setIdentifier($_SESSION['userid']);
							break;
						case 'username':
							$creditSystem->setIdentifier($_SESSION['username']);
							break;
						case 'character':
							$creditSystem->setIdentifier($character_name);
							break;
						default:
							throw new Exception("Invalid identifier (credit system).");
					}
					$creditSystem->addCredits(mconfig('resets_credits_reward'));
					
					message('success', langf('resetcharacter_txt_8', array(mconfig('resets_credits_reward'))));
				} catch (Exception $ex) {}
			}
			
		} catch(Exception $ex) {
			message('error', $ex->getMessage());
		}
	}
	
	public function CharacterResetStats() {
		// filters
		if(!check_value($this->_username)) throw new Exception(lang('error_21'));
		if(!check_value($this->_character)) throw new Exception(lang('error_21'));
		if(!check_value($this->_userid)) throw new Exception(lang('error_21'));
		if(!$this->CharacterExists($this->_character)) throw new Exception(lang('error_35'));
		if(!$this->CharacterBelongsToAccount($this->_character, $this->_username)) throw new Exception(lang('error_35'));
		
		// check online status
		$Account = new Account();
		if($Account->accountOnline($this->_username)) throw new Exception(lang('error_14'));
		
		// character data
		$characterData = $this->CharacterData($this->_character);
		
		// zen requirement
		$zenRequirement = mconfig('zen_cost');
		
		// credit requirement
		$creditConfig = mconfig('credit_config');
		$creditCost = mconfig('credit_cost');
		if($creditCost > 0 && $creditConfig != 0) {
			$creditSystem = new CreditSystem();
			$creditSystem->setConfigId($creditConfig);
			$configSettings = $creditSystem->showConfigs(true);
			switch($configSettings['config_user_col_id']) {
				case 'userid':
					$creditSystem->setIdentifier($this->_userid);
					break;
				case 'username':
					$creditSystem->setIdentifier($this->_username);
					break;
				case 'character':
					$creditSystem->setIdentifier($this->_character);
					break;
				default:
					throw new Exception("Invalid identifier (credit system).");
			}
			if($creditSystem->getCredits() < $creditCost) throw new Exception(langf('error_113', array($configSettings['config_title'])));
		}
		
		// check zen
		if($zenRequirement > 0) if($characterData[_CLMN_CHR_ZEN_] < $zenRequirement) throw new Exception(lang('error_34'));
		
		// base stats
		$base_stats = $this->_getClassBaseStats($characterData[_CLMN_CHR_CLASS_]);
		$base_stats_points = array_sum($base_stats);
		
		// calculate new level up points
		$levelUpPoints = $characterData[_CLMN_CHR_STAT_STR_]+$characterData[_CLMN_CHR_STAT_AGI_]+$characterData[_CLMN_CHR_STAT_VIT_]+$characterData[_CLMN_CHR_STAT_ENE_];
		if(array_key_exists(_CLMN_CHR_STAT_CMD_, $characterData)) {
			$levelUpPoints += $characterData[_CLMN_CHR_STAT_CMD_];
		}
		if($base_stats_points > 0) {
			$levelUpPoints -= $base_stats_points;
		}
		
		// query data
		$data = array_merge(
			array(
				'player' => $characterData[_CLMN_CHR_NAME_],
				'points' => $levelUpPoints,
				'zen' => $zenRequirement,
			),
			$base_stats
		);
		
		// query
		$query = "UPDATE "._TBL_CHR_." SET "._CLMN_CHR_STAT_STR_." = :str, "._CLMN_CHR_STAT_AGI_." = :agi, "._CLMN_CHR_STAT_VIT_." = :vit, "._CLMN_CHR_STAT_ENE_." = :ene";
		if(array_key_exists(_CLMN_CHR_STAT_CMD_, $characterData)) $query .= ", "._CLMN_CHR_STAT_CMD_." = :cmd";
		$query .= ", "._CLMN_CHR_ZEN_." = "._CLMN_CHR_ZEN_." - :zen";
		$query .= ", "._CLMN_CHR_LVLUP_POINT_." = "._CLMN_CHR_LVLUP_POINT_." + :points WHERE "._CLMN_CHR_NAME_." = :player";
		
		// reset stats
		$result = $this->muonline->query($query, $data);
		if(!$result) throw new Exception(lang('error_21'));
		
		// subtract credits
		if($creditCost > 0 && $creditConfig != 0) $creditSystem->subtractCredits($creditCost);
		
		// success
		message('success', lang('success_9'));
	}
	
	public function CharacterClearPK() {
		// filters
		if(!check_value($this->_username)) throw new Exception(lang('error_21'));
		if(!check_value($this->_character)) throw new Exception(lang('error_21'));
		if(!check_value($this->_userid)) throw new Exception(lang('error_21'));
		if(!$this->CharacterExists($this->_character)) throw new Exception(lang('error_36'));
		if(!$this->CharacterBelongsToAccount($this->_character, $this->_username)) throw new Exception(lang('error_36'));
		
		// check online status
		$Account = new Account();
		if($Account->accountOnline($this->_username)) throw new Exception(lang('error_14'));
		
		// character data
		$characterData = $this->CharacterData($this->_character);
		
		// check pk status
		if($characterData[_CLMN_CHR_PK_LEVEL_] == $this->_clearPkLevel) throw new Exception(lang('error_117'));
		
		// zen requirement
		$zenRequirement = mconfig('zen_cost');
		
		// credit requirement
		$creditConfig = mconfig('credit_config');
		$creditCost = mconfig('credit_cost');
		if($creditCost > 0 && $creditConfig != 0) {
			$creditSystem = new CreditSystem();
			$creditSystem->setConfigId($creditConfig);
			$configSettings = $creditSystem->showConfigs(true);
			switch($configSettings['config_user_col_id']) {
				case 'userid':
					$creditSystem->setIdentifier($this->_userid);
					break;
				case 'username':
					$creditSystem->setIdentifier($this->_username);
					break;
				case 'character':
					$creditSystem->setIdentifier($this->_character);
					break;
				default:
					throw new Exception("Invalid identifier (credit system).");
			}
			if($creditSystem->getCredits() < $creditCost) throw new Exception(langf('error_116', array($configSettings['config_title'])));
		}
		
		// check zen
		if($zenRequirement > 0) if($characterData[_CLMN_CHR_ZEN_] < $zenRequirement) throw new Exception(lang('error_34'));
		
		// query data
		$data = array(
			'player' => $characterData[_CLMN_CHR_NAME_],
			'pklevel' => $this->_clearPkLevel,
			'zen' => $zenRequirement,
		);
		
		// query
		$query = "UPDATE "._TBL_CHR_." SET "._CLMN_CHR_PK_LEVEL_." = :pklevel, "._CLMN_CHR_PK_TIME_." = 0, "._CLMN_CHR_ZEN_." = "._CLMN_CHR_ZEN_." - :zen WHERE "._CLMN_CHR_NAME_." = :player";
		
		// clear pk
		$result = $this->muonline->query($query, $data);
		if(!$result) throw new Exception(lang('error_21'));
		
		// subtract credits
		if($creditCost > 0 && $creditConfig != 0) $creditSystem->subtractCredits($creditCost);
		
		// success
		message('success', lang('success_10'));
	}
	
	public function CharacterUnstick() {
		// filters
		if(!check_value($this->_username)) throw new Exception(lang('error_21'));
		if(!check_value($this->_character)) throw new Exception(lang('error_21'));
		if(!check_value($this->_userid)) throw new Exception(lang('error_21'));
		if(!$this->CharacterExists($this->_character)) throw new Exception(lang('error_37'));
		if(!$this->CharacterBelongsToAccount($this->_character, $this->_username)) throw new Exception(lang('error_37'));
		
		// check online status
		$Account = new Account();
		if($Account->accountOnline($this->_username)) throw new Exception(lang('error_14'));
		
		// character data
		$characterData = $this->CharacterData($this->_character);
		
		// check position
		if($characterData[_CLMN_CHR_MAP_] == $this->_unstickMap) {
			if($characterData[_CLMN_CHR_MAP_X_] == $this->_unstickCoordX && $characterData[_CLMN_CHR_MAP_Y_] == $this->_unstickCoordY) throw new Exception(lang('error_115'));
		}
		
		// zen requirement
		$zenRequirement = mconfig('zen_cost');
		
		// credit requirement
		$creditConfig = mconfig('credit_config');
		$creditCost = mconfig('credit_cost');
		if($creditCost > 0 && $creditConfig != 0) {
			$creditSystem = new CreditSystem();
			$creditSystem->setConfigId($creditConfig);
			$configSettings = $creditSystem->showConfigs(true);
			switch($configSettings['config_user_col_id']) {
				case 'userid':
					$creditSystem->setIdentifier($this->_userid);
					break;
				case 'username':
					$creditSystem->setIdentifier($this->_username);
					break;
				case 'character':
					$creditSystem->setIdentifier($this->_character);
					break;
				default:
					throw new Exception("Invalid identifier (credit system).");
			}
			if($creditSystem->getCredits() < $creditCost) throw new Exception(langf('error_114', array($configSettings['config_title'])));
		}
		
		// check zen
		if($zenRequirement > 0) if($characterData[_CLMN_CHR_ZEN_] < $zenRequirement) throw new Exception(lang('error_34'));
		
		// deduct zen
		if(!$this->DeductZEN($this->_character, $zenRequirement)) throw new Exception(lang('error_34'));
		
		// move character
		$update = $this->moveCharacter($this->_character, $this->_unstickMap, $this->_unstickCoordX, $this->_unstickCoordY);
		if(!$update) throw new Exception(lang('error_21'));
		
		// subtract credits
		if($creditCost > 0 && $creditConfig != 0) $creditSystem->subtractCredits($creditCost);
		
		// success
		message('success', lang('success_11'));
	}
	
	public function CharacterClearSkillTree($username,$character_name) {
		try {
			if(!check_value($username)) throw new Exception(lang('error_23',true));
			if(!check_value($character_name)) throw new Exception(lang('error_23',true));
			if(!Validator::UsernameLength($username)) throw new Exception(lang('error_23',true));
			if(!Validator::AlphaNumeric($username)) throw new Exception(lang('error_23',true));
			if(!$this->CharacterExists($character_name)) throw new Exception(lang('error_38',true));
			if(!$this->CharacterBelongsToAccount($character_name,$username)) throw new Exception(lang('error_38',true));
			if($this->common->accountOnline($username)) throw new Exception(lang('error_14',true));
			
			$characterData = $this->CharacterData($character_name);
			$characterMLData = $this->getMasterLevelInfo($character_name);
			
			if(mconfig('clearst_enable_zen_requirement')) {
				if($characterData[_CLMN_CHR_ZEN_] < mconfig('clearst_price_zen')) throw new Exception(lang('error_34',true));
				$deductZen = $this->DeductZEN($character_name, mconfig('clearst_price_zen'));
				if(!$deductZen) throw new Exception(lang('error_34',true));
			}
			
			if($characterMLData[_CLMN_ML_LVL_] < mconfig('clearst_required_level')) throw new Exception(lang('error_39',true).mconfig('clearst_required_level'));
			
			// CLEAR CHARACTER MASTER SKILL TREE DATA
			$update = $this->resetMasterLevelData($character_name);
			if(!$update) throw new Exception(lang('error_23',true));
			
			// CLEAR MAGICLIST DATA
			$update_2 = $this->resetMagicList($character_name);
			if(!$update_2) throw new Exception(lang('error_23',true));
			
			// SUCCESS
			message('success', lang('success_12',true));
			
		} catch(Exception $ex) {
			message('error', $ex->getMessage());
		}
	}
	
	public function CharacterAddStats($username,$character_name,$str=0,$agi=0,$vit=0,$ene=0,$com=0) {
		global $custom;
		try {
			if(!check_value($username)) throw new Exception(lang('error_23',true));
			if(!check_value($character_name)) throw new Exception(lang('error_23',true));
			if(!Validator::UsernameLength($username)) throw new Exception(lang('error_23',true));
			if(!Validator::AlphaNumeric($username)) throw new Exception(lang('error_23',true));
			if(!$this->CharacterExists($character_name)) throw new Exception(lang('error_64',true));
			if(!$this->CharacterBelongsToAccount($character_name,$username)) throw new Exception(lang('error_64',true));
			if($this->common->accountOnline($username)) throw new Exception(lang('error_14',true));
			
			$characterData = $this->CharacterData($character_name);
			
			if($str < 1) { $str = 0; }
			if($agi < 1) { $agi = 0; }
			if($vit < 1) { $vit = 0; }
			if($ene < 1) { $ene = 0; }
			if($com < 1) { $com = 0; }
			
			$total_add_points = $str+$agi+$vit+$ene+$com;
			if($total_add_points < mconfig('addstats_minimum_add_points')) throw new Exception(lang('error_54',true).mconfig('addstats_minimum_add_points'));
			if($total_add_points > $characterData[_CLMN_CHR_LVLUP_POINT_]) throw new Exception(lang('error_51',true));
			
			if($com >= 1) {
				if(!in_array($characterData[_CLMN_CHR_CLASS_], $custom['character_cmd'])) throw new Exception(lang('error_52',true));
			}
			
			$max_stats = mconfig('addstats_max_stats');
			$sum_str = $str+$characterData[_CLMN_CHR_STAT_STR_];
			$sum_agi = $agi+$characterData[_CLMN_CHR_STAT_AGI_];
			$sum_vit = $vit+$characterData[_CLMN_CHR_STAT_VIT_];
			$sum_ene = $ene+$characterData[_CLMN_CHR_STAT_ENE_];
			$sum_com = $com+$characterData[_CLMN_CHR_STAT_CMD_];
			
			if($sum_str > $max_stats) throw new Exception(lang('error_53',true));
			if($sum_agi > $max_stats) throw new Exception(lang('error_53',true));
			if($sum_vit > $max_stats) throw new Exception(lang('error_53',true));
			if($sum_ene > $max_stats) throw new Exception(lang('error_53',true));
			if($sum_com > $max_stats) throw new Exception(lang('error_53',true));
			
			if(mconfig('addstats_enable_zen_requirement')) {
				if($characterData[_CLMN_CHR_ZEN_] < mconfig('addstats_price_zen')) throw new Exception(lang('error_34',true));
				$deductZen = $this->DeductZEN($character_name, mconfig('addstats_price_zen'));
				if(!$deductZen) throw new Exception(lang('error_34',true));
			}
			
			$query = $this->muonline->query("UPDATE "._TBL_CHR_." SET 
			"._CLMN_CHR_STAT_STR_." = "._CLMN_CHR_STAT_STR_." + ?,
			"._CLMN_CHR_STAT_AGI_." = "._CLMN_CHR_STAT_AGI_." + ?,
			"._CLMN_CHR_STAT_VIT_." = "._CLMN_CHR_STAT_VIT_." + ?,
			"._CLMN_CHR_STAT_ENE_." = "._CLMN_CHR_STAT_ENE_." + ?,
			"._CLMN_CHR_STAT_CMD_." = "._CLMN_CHR_STAT_CMD_." + ?,
			"._CLMN_CHR_LVLUP_POINT_." = "._CLMN_CHR_LVLUP_POINT_." - ? 
			WHERE "._CLMN_CHR_NAME_." = ?", array($str, $agi, $vit, $ene, $com, $total_add_points, $character_name));
			if(!$query) throw new Exception(lang('error_23',true));
			
			// SUCCESS
			message('success',lang('success_17',true));
			
		} catch(Exception $ex) {
			message('error', $ex->getMessage());
		}
	}
	
	public function AccountCharacter($username) {
		if(!check_value($username)) return;
		if(!Validator::UsernameLength($username)) return;
		if(!Validator::AlphaNumeric($username)) return;
		
		$result = $this->muonline->query_fetch("SELECT "._CLMN_CHR_NAME_." FROM "._TBL_CHR_." WHERE "._CLMN_CHR_ACCID_." = ?", array($username));
		if(!is_array($result)) return;
		
		foreach($result as $row) {
			if(!check_value($row[_CLMN_CHR_NAME_])) continue;
			$return[] = $row[_CLMN_CHR_NAME_];
		}
		
		if(!is_array($return)) return;
		return $return;
	}
	
	public function CharacterData($character_name) {
		if(!check_value($character_name)) return;
		$result = $this->muonline->query_fetch_single("SELECT * FROM "._TBL_CHR_." WHERE "._CLMN_CHR_NAME_." = ?", array($character_name));
		if(!is_array($result)) return;
		return $result;
		
	}
	
	public function CharacterBelongsToAccount($character_name,$username) {
		if(!check_value($character_name)) return;
		if(!check_value($username)) return;
		if(!Validator::UsernameLength($username)) return;
		if(!Validator::AlphaNumeric($username)) return;
		$characterData = $this->CharacterData($character_name);
		if(!is_array($characterData)) return;
		if(strtolower($characterData[_CLMN_CHR_ACCID_]) != strtolower($username)) return;
		return true;
		
	}
	
	public function CharacterExists($character_name) {
		if(!check_value($character_name)) return;
		$check = $this->muonline->query_fetch_single("SELECT * FROM "._TBL_CHR_." WHERE "._CLMN_CHR_NAME_." = ?", array($character_name));
		if(!is_array($check)) return;
		return true;
	}
	
	public function DeductZEN($character_name,$zen_amount) {
		if(!check_value($character_name)) return;
		if(!check_value($zen_amount)) return;
		if(!Validator::UnsignedNumber($zen_amount)) return;
		if($zen_amount < 1) return;
		if(!$this->CharacterExists($character_name)) return;
		$characterData = $this->CharacterData($character_name);
		if(!is_array($characterData)) return;
		if($characterData[_CLMN_CHR_ZEN_] < $zen_amount) return;
		$deduct = $this->muonline->query("UPDATE "._TBL_CHR_." SET "._CLMN_CHR_ZEN_." = "._CLMN_CHR_ZEN_." - ? WHERE "._CLMN_CHR_NAME_." = ?", array($zen_amount, $character_name));
		if(!$deduct) return;
		return true;
	}
	
	public function moveCharacter($character_name,$map=0,$x=125,$y=125) {
		if(!check_value($character_name)) return;
		$move = $this->muonline->query("UPDATE "._TBL_CHR_." SET "._CLMN_CHR_MAP_." = ?, "._CLMN_CHR_MAP_X_." = ?, "._CLMN_CHR_MAP_Y_." = ? WHERE "._CLMN_CHR_NAME_." = ?", array($map, $x, $y, $character_name));
		if(!$move) return;
		return true;
	}
	
	public function AccountCharacterIDC($username) {
		if(!check_value($username)) return;
		if(!Validator::UsernameLength($username)) return;
		if(!Validator::AlphaNumeric($username)) return;
		$data = $this->muonline->query_fetch_single("SELECT * FROM "._TBL_AC_." WHERE "._CLMN_AC_ID_." = ?", array($username));
		if(!is_array($data)) return;
		return $data[_CLMN_GAMEIDC_];
	}
	
	// To be removed (backwards compatibility)
	public function GenerateCharacterClassAvatar($code=0,$alt=true,$img_tags=true) {
		return getPlayerClassAvatar($code, $img_tags, $alt, 'tables-character-class-img');
	}
	
	public function getMasterLevelInfo($character_name) {
		if(!check_value($character_name)) return;
		if(!$this->CharacterExists($character_name)) return;
		$CharInfo = $this->muonline->query_fetch_single("SELECT * FROM "._TBL_MASTERLVL_." WHERE "._CLMN_ML_NAME_." = ?", array($character_name));
		if(!is_array($CharInfo)) return;
		return $CharInfo;
	}
	
	public function resetMasterLevelData($character_name) {
		if(!check_value($character_name)) return;
		if(!$this->CharacterExists($character_name)) return;
		if(defined(_CLMN_ML_NEXP_)) {
			$reset = $this->muonline->query("UPDATE "._TBL_MASTERLVL_." SET "._CLMN_ML_LVL_." = 0,"._CLMN_ML_EXP_." = 0,"._CLMN_ML_NEXP_." = '35507050',"._CLMN_ML_POINT_." = 0 WHERE "._CLMN_ML_NAME_." = ?", array($character_name));
		} else {
			$reset = $this->muonline->query("UPDATE "._TBL_MASTERLVL_." SET "._CLMN_ML_LVL_." = 0,"._CLMN_ML_EXP_." = 0,"._CLMN_ML_POINT_." = 0 WHERE "._CLMN_ML_NAME_." = ?", array($character_name));
		}
		if(!$reset) return;
		return true;
	}
	
	public function resetMagicList($character_name) {
		if(!check_value($character_name)) return;
		if(!$this->CharacterExists($character_name)) return;
		$reset = $this->muonline->query("UPDATE "._TBL_CHR_." SET "._CLMN_CHR_MAGIC_L_." = null WHERE "._CLMN_CHR_NAME_." = ?", array($character_name));
		if(!$reset) return;
		return true;
	}
	
	protected function _getClassBaseStats($class) {
		if(!array_key_exists($class, $this->_classData)) throw new Exception(lang('error_109'));
		if(!array_key_exists('base_stats', $this->_classData[$class])) throw new Exception(lang('error_110'));
		if(!is_array($this->_classData[$class]['base_stats'])) throw new Exception(lang('error_110'));
		return $this->_classData[$class]['base_stats'];
	}
	
}