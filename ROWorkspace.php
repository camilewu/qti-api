<?php
require_once 'MemberService.php';
// ini_set('opcache.enable', 0);
use App\Lib\Curl;
use App\Lib\CurlCreator;

class ROWorkspace extends MemberService
{
	public function __construct()
	{
		parent::__construct();
		error_reporting(-1);
		ini_set('display_errors', 'On');
		// $this->load->database();
		$host = $_SERVER['HTTP_HOST'];
		if ((strpos($host, 'ken') !== false || strpos($host, 'ro2') !== false) && sizeof($this->account) == 0) {
			$this->account = [
				'uid' => 511, 'school_id' => 272, 'user_role' => 3, 'display_name' => 'oka1A 老師', 'debug' => 1, 'uacc_username' => 'oka-002'
				// 'uid'=> 42, 'school_id' => 272, 'user_role' => 2, 'display_name' => '黃蘇菲', 'debug' => 1, 'uacc_username' => 'oka-unknown','gender' => 1
				// 'uid'=> 558, 'school_id' => 272, 'user_role' => 3, 'display_name' => 'TsangEdward Ed', 'uacc_username' => 'unknown'
				// 'uid' => 138383, 'school_id' => 22891, 'user_role' => 3, 'display_name' => '1L好學生', 'uacc_username' => 'unknown'
				// 'uid' => 155895, 'school_id' => 36146, 'user_role' => 3, 'display_name' => '港專老師', 'uacc_username' => 'unknown'
				// 'uid' => "18156", 'school_id' => 316, 'user_role' => 3, 'display_name' => '老師1A', 'uacc_username' => 'unknown'
			];
		}
		$this->disableCORS();
	}

	private function disableCORS()
	{
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE');
		header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, X-Token, x-token');
	}
	/*
	public function test()
	{
		return $this->get_branch_tags();
	}
	*/

	private function get_branch_tags($school_year = 0)
	{
		$this->load->library("sails");
		$school_id = $this->account["school_id"];
		$uid = $this->account["uid"];
		$this->link_library("school_info", "ROSchoolInfo");
		$school = $this->school_info->get_school_info($school_id);
		$branch = false;
		if ($school->addon_branch) {
			$branch = $this->school_info->is_in_branch_account($uid);
		}
		if ($branch) {
			return $this->school_info->get_all_branch_tag($school_id, $uid, $school_year);
		} else {
			return null;
		}
	}

	public function getInfo()
	{
		if (empty($this->account)) {
			return ['code' => 11, 'msg' => 'LOGIN_REQUIRED'];
		}
		$uid = $this->account['uid'];
		$school_id = $this->account['school_id'];
		$permission_definitions = $this->fetch_rows("SELECT `view`,edit,share,clear_answer,publish,`rename`,`delete`,`restore`,copy_to_others,`copy`,`move`,permission,sort,`name`,id FROM ro_entry_share_permission_definition WHERE `type` = 'entry'", []);
		$obj = $this->list_entries($school_id);
		return ['account' => $this->account, 'entries' => $obj, 'permission_definitions' => $permission_definitions];
	}

	public function get_school_year_and_tag()
	{
		set_time_limit(120);
		$tags = $this->get_tags();
		$school_years = $this->fetch_rows("SELECT id, title, start_time, end_time FROM ro_school_year WHERE school_id=? AND active = 1 ORDER BY end_time DESC", [$this->account['school_id']]);
		return ['tags' => $tags, 'school_years' => $school_years, 'account' => $this->account];
	}

	private function get_tags()
	{
		$sql = "
			SELECT a.id, a.pid, a.title, a.type, a.rid, a.school_year, a.gtype, d.title AS year_name,
			(SELECT EXISTS(SELECT * FROM `ro_groups` b WHERE b.uid = ? AND b.active = 1 AND b.tid = a.id LIMIT 1)) AS my_tag 
			FROM `ro_school_tags` a
			LEFT JOIN `ro_school_tags` c ON a.id = c.pid AND c.active = 1
			INNER JOIN `ro_school_year` d ON d.id = a.school_year AND d.active = 1
			WHERE a.rid = ? AND a.active = 1 AND ((c.id IS NOT NULL AND a.type = 9) OR a.type = 3) GROUP BY a.id ORDER BY a.title ASC
		";
		$arr = [$this->account['uid'], $this->account['school_id']];
		return $this->fetch_rows($sql, $arr);
	}
	private function assign_as_array(&$map, &$array, $id_key, $target_key)
	{
		foreach ($array as $element) {
			$key = $element->$id_key;
			if (isset($map[$key])) $map[$key]->$target_key[] = $element;
		}
	}
	private function assign_as_value(&$map, &$array, $id_key, $target_key)
	{
		foreach ($array as $element) {
			$key = $element->$id_key;
			if (isset($map[$key])) $map[$key]->$target_key  = $element;
		}
	}
	private function create_map($array, $key)
	{
		$pointer = (object)[
			"map" => [],
			"id_array" => []
		];
		foreach ($array as $element) {
			$key_2 = $element->$key;
			$pointer->id_array[] = $key_2;
			$pointer->map[$key_2] = $element;
		}
		$pointer->id_string = implode(",", $pointer->id_array);
		return $pointer;
	}

	public function get_uid_in_group($group_id_list)
	{
		$group_id_list = implode(",", $group_id_list);
		$uid_list = $this->fetch_rows("SELECT uid FROM ro_groups Where tid IN(?) AND active = 1", [$group_id_list]);
		return ["data" => $uid_list];
	}

	public function get_school_member_info($option = "all")
	{
		$school_id = $this->account["school_id"];
		$uid = $this->account['uid'];
		if ($option == "teacher") {
			$rows = $this->fetch_rows("SELECT * From member_info Where school_id = ? AND active = 1 AND user_role = 3", [$school_id]);
		} elseif ($option == "all") {
			$rows = $this->fetch_rows("SELECT * From member_info Where school_id = ? AND active = 1", [$school_id]);
		}
		return ["data" => $rows];
	}

	public function loop_parent($root_id, $target_id, $pid_list)
	{
		$this_pid = $this->fetch_rows("SELECT pid FROM ro_entries Where id = ?", [$target_id]);
		if (count($this_pid) == 0) {
			return "error";
		}
		$this_pid = $this_pid[0]->pid;
		array_push($pid_list, $this_pid);
		if (sizeof($pid_list) > 30) {
			return $pid_list;
		}
		if ($this_pid != $root_id && $this_pid != 0) {
			$pid_list = $this->loop_parent($root_id, $this_pid, $pid_list);
			if ($pid_list == "error") {
				return "error";
			}
		}
		return $pid_list;
	}

	public function get_entries_parent($root_id, $target_id)
	{
		$school_id = $this->account["school_id"];
		$uid = $this->account['uid'];
		$pid_list = [];
		$pid_list = $this->loop_parent($root_id, $target_id, $pid_list);
		// $pid_list = $this->fetch_rows("SELECT pid FROM ro_entries Where id = ?",[$target_id]);
		if ($pid_list == "error") {
			return ["error" => "error"];;
		}
		return ["pid_list" => $pid_list];
	}

	public function private_search_list_entries($search_text = "", $search_criteria = [])
	{
		set_time_limit(120);
		$school_id = $this->account["school_id"];
		$uid = $this->account['uid'];

		$type_string = "";
		$creator_string = "";
		$period_string = "";
		$join_subject_table = "";
		$subject_string = "";

		if (isset($search_criteria->type_string)) {
			$type_string = " And a.type in " . $search_criteria->type_string;
		}
		if (isset($search_criteria->creator_string)) {
			$creator_string = " And a.uid in " . $search_criteria->creator_string;
		}
		if (isset($search_criteria->period_start_timestamp)) {
			$period_string = " And a.creation_time > " . $search_criteria->period_start_timestamp;
			if (isset($search_criteria->period_end_timestamp)) {
				$period_string = $period_string . " And a.creation_time < " . $search_criteria->period_end_timestamp;
			}
		}
		if (isset($search_criteria->subject)) {
			$first_subject = true;
			$subject_criteria = "";

			foreach ($search_criteria->subject as $subject) {
				if ($first_subject == true) {
					$first_subject = false;
				} else {
					$subject_criteria = $subject_criteria . " OR ";
				}
				if (is_null($subject->school_subject_id)) {
					$school_subject_id = "IS NULL";
				} else {
					$school_subject_id = "=" . $subject->school_subject_id;
				}
				$public_subject_id = $subject->public_subject_id;
				$type = $subject->type;
				$subject_criteria = $subject_criteria . "(b.type = '$type' AND b.school_subject_id $school_subject_id And b.public_subject_id = $public_subject_id)";
			}
			$subject_string = " And ($subject_criteria)";
		}

		$sql = "SELECT a.id,a.pid,a.title,a.type,a.children,a.checksum,a.permission,a.ref,a.uid,a.owner,a.creation_time,b.school_subject_id,b.public_subject_id,b.type as subject_type,c.cover,d.period
			FROM ro_entries as a
			Left Join ro_entry_subject as b On b.entry_id = a.id
			Left Join ro_entry_cover as c On c.entry_id = a.id
			Left Join ro_entry_valid_period as d On d.entry_id = a.id
			WHERE (a.uid = $uid OR a.uid IS NULL) 
			AND a.is_private = '1'
			AND a.school_id = $school_id 
			AND a.active = 1 
			AND Lower(a.title) LIKE Lower('%$search_text%') 
			AND a.type != 'doc'
			$type_string $creator_string $period_string $subject_string
		";

		$rows = $this->fetch_rows($sql);

		return ["entries" => $rows, "search_criteria" => $search_criteria, "sql" => $sql];
	}


	public function public_search_list_entries($search_text = "", $search_criteria = [])
	{
		set_time_limit(120);
		$school_id = $this->account["school_id"];
		$uid = $this->account['uid'];

		$type_string = "";
		$creator_string = "";
		$period_string = "";
		$subject_string = "";

		if (isset($search_criteria->type_string)) {
			$type_string = " And a.type in " . $search_criteria->type_string;
		}
		if (isset($search_criteria->creator_string)) {
			$creator_string = " And a.uid in " . $search_criteria->creator_string;
		}
		if (isset($search_criteria->period_start_timestamp)) {
			$period_string = " And a.creation_time > " . $search_criteria->period_start_timestamp;
			if (isset($search_criteria->period_end_timestamp)) {
				$period_string = $period_string . " And a.creation_time < " . $search_criteria->period_end_timestamp;
			}
		}
		if (isset($search_criteria->subject)) {
			$first_subject = true;
			$subject_criteria = "";

			foreach ($search_criteria->subject as $subject) {
				if ($first_subject == true) {
					$first_subject = false;
				} else {
					$subject_criteria = $subject_criteria . " OR ";
				}
				if (is_null($subject->school_subject_id)) {
					$school_subject_id = "IS NULL";
				} else {
					$school_subject_id = "=" . $subject->school_subject_id;
				}
				$public_subject_id = $subject->public_subject_id;
				$type = $subject->type;
				$subject_criteria = $subject_criteria . "(b.type = '$type' AND b.school_subject_id $school_subject_id And b.public_subject_id = $public_subject_id)";
			}
			$subject_string = " And ($subject_criteria)";
		}

		$sql  = "SELECT a.id,a.pid,a.title,a.type,a.children,a.checksum,a.permission,a.ref,a.uid,a.owner,a.creation_time,b.school_subject_id,b.public_subject_id,b.type as subject_type ,c.cover,d.period
			FROM ro_entries as a
			Left Join ro_entry_subject as b On b.entry_id = a.id
			Left Join ro_entry_cover as c On c.entry_id = a.id
			Left Join ro_entry_valid_period as d On d.entry_id = a.id
			WHERE a.is_private != '1' 
			AND a.school_id = $school_id
			AND a.active = 1 
			AND Lower(a.title) LIKE Lower('%$search_text%') 
			AND a.type != 'doc'
			$type_string $creator_string $period_string $subject_string
		";

		$rows = $this->fetch_rows($sql);


		return ["entries" => $rows, "search_criteria" => $search_criteria, "sql" => $sql];
	}

	public function list_entries($pid = 0, $deleted = 0, $search_text = "")
	{
		set_time_limit(120);
		$school_id = $this->account["school_id"];
		$uid = $this->account['uid'];
		if ($pid == $school_id) {
			$root = null;
			if ($this->account['uid'] == 422101) { // qef readingstar 只看見readingstar繪本
				$root = $this->fetch_row("SELECT id,pid,title,type,children,ref, owner,creation_time FROM ro_entries WHERE id = 2424926 AND active = 1");
			} else {
				$root = $this->fetch_row("SELECT id,pid,title,type,children,ref, owner,creation_time FROM ro_entries WHERE pid = 0 AND active = 1 AND owner = ?", [$pid]);
			}

			$rows2 = $this->fetch_rows("SELECT id,pid,title,type,children,ref, owner,creation_time FROM ro_entries WHERE `type`='my_folder' AND pid = 0 AND active = 1 AND is_private=1 AND owner = ? LIMIT 1", [$uid]);
			if ($root) {
				$rows = $this->fetch_rows("SELECT id,pid,title,type,children,checksum,permission,ref, owner,creation_time FROM ro_entries WHERE pid = ? AND school_id = ? AND active = 1", [$root->id, $school_id]);
				if (count($rows))
					$this->assign_entries_permissions($rows);

				$personal_root = null;
				$personal_entries = [];
				if (isset($rows2[0])) {
					$personal_root = $rows2[0];
					$personal_entries = $this->fetch_rows("
						SELECT id,pid,title,type,children,checksum,permission ,ref, owner,creation_time
						FROM ro_entries 
						WHERE school_id = ? AND active = 1 AND pid=?", [$school_id, $rows2[0]->id]);
						// owner = ? AND $uid, 
					if (count($personal_entries)) $this->assign_entries_permissions($personal_entries);
				}
				return ['code' => 0, 'entries' => $rows ? $rows : [], 'root' => $root, 'personal_root' => $personal_root, 'personal_entries' => $personal_entries, 'search_text' => $search_text];
			}
		} else {
			$rows = $this->fetch_rows("
				SELECT id,pid,title,type,children,checksum,permission,ref, owner ,creation_time FROM ro_entries 
				WHERE pid = ? AND school_id = ? AND active = ?
				", [$pid, $school_id, $deleted == 1 ? 2 : 1]);
			if (count($rows)) {
				$this->assign_entries_permissions($rows);
			}

			$self = $this->fetch_rows("
				SELECT id,pid,title,type,children,checksum,permission,ref, owner ,creation_time FROM ro_entries 
				WHERE id = ? AND school_id = ? AND active = ?
			", [$pid, $school_id, $deleted == 1 ? 0 : 1]);
			return ["code" => 0, "entries" => $rows ? $rows : [], "self" => $self];
		}
	}

	private function assign_entries_permissions($entries)
	{
		foreach ($entries as $entry) {
			$entry->entry_permission = [];
			$entry->share_permissions = [];
			$entry->subjects = [];
		}
		$pointer = $this->create_map($entries, "id");
		$id_array_string = $pointer->id_string;
		$entry_permission_array = $this->fetch_rows("SELECT * FROM ro_entry_permission WHERE id IN($id_array_string) ");
		$share_permission_array = $this->fetch_rows("SELECT * FROM ro_entry_share_permission WHERE entry_id IN($id_array_string)");
		$subject_array = $this->fetch_rows("SELECT public_subject_id,school_subject_id,entry_id FROM ro_entry_subject WHERE entry_id IN($id_array_string) ");
		$cover_array =  $this->fetch_rows("SELECT cover,entry_id FROM ro_entry_cover WHERE entry_id IN($id_array_string) ");
		$period_array =  $this->fetch_rows("SELECT period,entry_id FROM ro_entry_valid_period WHERE entry_id IN($id_array_string) ");

		$this->assign_as_value($pointer->map, $entry_permission_array, "id", "entry_permission");
		$this->assign_as_array($pointer->map, $share_permission_array, "entry_id", "share_permissions");
		$this->assign_as_array($pointer->map, $subject_array, "entry_id", "subjects");
		$this->assign_as_value($pointer->map, $cover_array, "entry_id", "cover");
		$this->assign_as_value($pointer->map, $period_array, "entry_id", "period");
	}

	public function get_tag_people($tag_id = 0, $target = 'all')
	{
		$target_condition = '1=1';
		if ($target == 'student') {
			$target_condition = 'b.user_role=2';
		} elseif ($target == 'teacher') {
			$target_condition = 'b.user_role>2 AND b.user_role <> 6';
		}
		$uids = $this->fetch_rows("SELECT a.uid FROM ro_groups a INNER JOIN member_info b ON a.uid = b.uid WHERE a.active = 1 AND a.tid=? AND $target_condition", [$tag_id]);
		return ['uids' => $uids];
	}

	public function save_permission($entry_id, $share_permissions = [])
	{
		$now = date("Y-m-d H:i:s");
		$exists = $this->fetch_rows("SELECT * FROM ro_entry_share_permission WHERE entry_id = ?", [$entry_id]);
		foreach ($share_permissions as $p) {
			$found = false;
			foreach ($exists as $k => $v) {
				if ($p->target_type == $v->target_type && $p->school_year == $v->school_year && $p->target == $v->target && $p->permission_type == $v->permission_type) {
					$found = $v->id;
					break;
				}
			}
			if (!$found) {
				$sql = "
				INSERT INTO ro_entry_share_permission 
				(entry_id,target,target_type,permission_type,school_year,updated_at,created_at,created_by)
				VALUES (?,?,?,?,?,?,?,?)";
				$arr = [$entry_id, $p->target, $p->target_type, $p->permission_type, $p->school_year, $now, $now, $this->account['uid']];
				$this->db->query($sql, $arr);
			} else {
				$exists = array_filter($exists, function ($e) use ($found) {
					return $e->id != $found;
				});
			}
		}
		if (sizeof($exists) > 0) {
			$removed = array_map(function ($e) {
				return $e->id;
			}, $exists);
			$removed_ids = implode(",", $removed);
			$this->db->query("DELETE FROM ro_entry_share_permission WHERE id IN ($removed_ids)");
		}
		$share_permissions = $this->fetch_rows("SELECT * FROM ro_entry_share_permission WHERE entry_id = ?", [$entry_id]);
		return ['entry_id' => $entry_id, 'share_permissions' => $share_permissions];
	}

	public function rename_entry($entry_id, $name)
	{
		$this->db->query("UPDATE ro_entries SET title = ? WHERE id = ?", [$name, $entry_id]);
		return ['code' => 1];
	}

	public function set_entry_pid($entry_ids, $pid, $is_private=0 , $owner = null)
	{
		$entry_ids = preg_replace('/[^\d,]/', '', $entry_ids);
		$this->db->query("UPDATE ro_entries SET pid = ?, active = 1, is_private = ?, owner = ? WHERE id IN($entry_ids)", [$pid,$is_private,$owner]);
		return ['code' => 1];
	}

	public function getTagsAndMembers()
	{
		if (empty($this->account)) {
			return ["code" => 401];
		}
		$uid = $this->account['uid'];
		$school_id = $this->account['school_id'];
		$this->load->library("sails");
		$this->link_library("school_info", "ROSchoolInfo");
		$school = $this->school_info->get_school_info($school_id);
		$branch = false;
		if ($school->addon_branch) {
			$branch = $this->school_info->is_in_branch_account($uid);
		}
		$time = time();
		if ($branch) {
			$tags = $this->school_info->get_all_branch_tag($school_id, $uid);
		} else {
			$tags = $this->fetch_rows(
				"SELECT a.id, a.pid, a.title, a.type, a.gtype, a.school_year, d.title AS year_name,
				(SELECT EXISTS(SELECT * FROM `ro_groups` b WHERE b.uid = ? AND b.active = 1 AND b.tid = a.id LIMIT 1)) AS my_tag
				FROM `ro_school_tags` a
				LEFT JOIN `ro_school_tags` c ON a.id = c.pid AND c.active = 1
				INNER JOIN `ro_school_year` d ON d.id = a.school_year AND d.active = 1 AND start_time <= ? AND end_time >= ?
				WHERE a.rid = ? AND a.active = 1 AND ((c.id IS NOT NULL AND a.type = 9) OR a.type = 3) GROUP BY a.id ORDER BY a.title ASC",
				[$uid, $time, $time, $school_id]
			);
		}
		$groups = $this->fetch_rows(
			"SELECT tid, uid, type, school_year_id, active FROM ro_groups
			WHERE active = 1 AND tid IN (?);",
			implode(",", array_column($tags, 'id'))
		);

		$tids = array_column($tags, 'id');
		$membersSql = "
        SELECT a.uid, a.display_name, a.gender, a.user_role
        FROM member_info a
        WHERE a.school_id = ?
        AND a.active = 1
        AND a.uid IN (SELECT uid FROM openknowledge_learnos.ro_groups WHERE active=1 AND tid in (?))
        ";
		$membersArr = [$school_id, implode(",", $tids)];
		$members = $this->fetch_rows($membersSql, $membersArr);
		return ["code" => 0, "tags" => $tags, "groups" => $groups, "members" => $members];
	}

	public function setFavSubject($id)
	{
		$uid = $this->account['uid'];
		$row = $this->fetch_row("SELECT personalSettings FROM member_info WHERE uid=?", [$uid]);
		$workspace_fav_subjects = [];
		$personalSettings = new stdClass();
		$personalSettings->workspace_fav_subjects = [];
		if (isset($row->personalSettings)) {
			$obj = json_decode($row->personalSettings);
			if ($obj != null) {
				$personalSettings = $obj;
				if (!isset($personalSettings->workspace_fav_subjects)) {
					$personalSettings->workspace_fav_subjects = [];
				}
			}
		}
		if (in_array($id, $personalSettings->workspace_fav_subjects)) {
			$arr = [];
			foreach ($personalSettings->workspace_fav_subjects as $sj) {
				if ($sj != $id) {
					$arr[] = $sj;
				}
			}
			$personalSettings->workspace_fav_subjects = $arr;
		} else {
			$personalSettings->workspace_fav_subjects[] = $id;
		}
		$updated = json_encode($personalSettings);
		$this->db->query('UPDATE member_info SET personalSettings=? WHERE uid=?', [$updated, $uid]);
		return ['personalSettings' => $personalSettings];
	}

	public function setFavTagId($tid)
	{
		$uid = $this->account['uid'];
		$row = $this->fetch_row("SELECT personalSettings FROM member_info WHERE uid=?", [$uid]);
		$fav_tag_ids = [];
		$personalSettings = new stdClass();
		$personalSettings->fav_tag_ids = [];
		if (isset($row->personalSettings)) {
			$obj = json_decode($row->personalSettings);
			if ($obj != null) {
				$personalSettings = $obj;
				if (!isset($personalSettings->fav_tag_ids)) {
					$personalSettings->fav_tag_ids = [];
				}
			}
		}
		if (in_array($tid, $personalSettings->fav_tag_ids)) {
			$arr = [];
			foreach ($personalSettings->fav_tag_ids as $fav_tag_id) {
				if ($fav_tag_id != $tid) {
					$arr[] = $fav_tag_id;
				}
			}
			$personalSettings->fav_tag_ids = $arr;
		} else {
			$personalSettings->fav_tag_ids[] = $tid;
		}
		$updated = json_encode($personalSettings);
		$this->db->query('UPDATE member_info SET personalSettings=? WHERE uid=?', [$updated, $uid]);
		return ['personalSettings' => $personalSettings];
	}

	public function test()
	{
		return 1;
	}

	public function isLocked($entry_id)
	{
		if ($this->account['user_role'] < 3) {
			return ['code' => 0, 'msg' => 'not-a-teacher'];
		} else {
			$row = $this->fetch_row(
				"SELECT a.*, CONCAT(b.first_name,' ',b.last_name) AS ename, 
				concat(b.c_last_name, b.c_first_name) AS cname 
				FROM  `ro_doc_lock` a 
				LEFT JOIN member_info b ON a.uid= b.uid
				WHERE a.`url` = ? 
				LIMIT 1",
				[$entry_id]
			);
			// $row = $this->fetch_row("SELECT uid,cname,ename,time FROM ro_doc_lock_view WHERE doc_id = ? AND school_id=? LIMIT 1", [$entry_id, $this->account['school_id'] ]);
			if ($row) {
				return ['code' => 0, 'log' => $row];
			} else {
				return ['code' => 1, "t" => 2];
			}
		}
	}

	public function addFileLink($entry_id, $url, $fileType = 'other')
	{
		$school_id = isset($this->account['school_id']) ? $this->account['school_id'] : 0;
		if (strpos($url, 'tmp_upload') !== false) {
			$url = substr($url, strpos($url, 'tmp_upload'));
			$url = '/home/site/wwwroot/RainbowOne/' . $url;
			$prefix = $this->create_prefix($this->account['school_id'], 'workspace');
			$url = $this->build_asset(null, $url, $prefix);
		}
		$obj = new StdClass();
		$obj->url = $url;
		$json = json_encode($obj);
		$rows = $this->fetch_rows("SELECT * FROM ro_entry_detail WHERE entry_id=? LIMIT 1", [$entry_id]);
		$row = null;
		if (sizeof($rows) > 0) {
			$this->db->query("UPDATE ro_entry_detail SET `type`=?, json=? WHERE id=?", [$fileType, $json, $rows[0]->id]);
			$row = $this->fetch_row("SELECT * FROM ro_entry_detail WHERE id=?", [$rows[0]->id]);
		} else {
			$this->db->query("INSERT INTO ro_entry_detail (`type`,entry_id, json) VALUES(?,?,?)", [$fileType, $entry_id, $json]);
			$insert_id = $this->db->insert_id();
			$row = $this->fetch_row("SELECT * FROM ro_entry_detail WHERE id=?", [$insert_id]);
		}
		$book_structure_row = $this->fetch_row("SELECT * FROM ro_book_structure WHERE unique_id = ? LIMIT 1", ["ro_entries/$entry_id"]);
		if (empty($book_structure_row)) {
			$this->insert_book_structure($entry_id, $url, $fileType);
		}
		return ['row' => $row];
	}

	private function insert_book_structure($entry_id, $url, $file_type)
	{
		$row = $this->fetch_row("SELECT * FROM ro_entries WHERE id = ?", [$entry_id]);
		$pageid = bin2hex(random_bytes(16));
		$pageid = substr($pageid, 0, 8) . "-" . substr($pageid, 8, 4) . "-" . substr($pageid, 12, 4) . "-" . substr($pageid, 16, 4) . "-" . substr($pageid, 20, 12);
		$compid = bin2hex(random_bytes(16));
		$compid = substr($compid, 0, 8) . "-" . substr($compid, 8, 4) . "-" . substr($compid, 12, 4) . "-" . substr($compid, 16, 4) . "-" . substr($compid, 20, 12);
		$componentStructure = '{"qInfo":{"level":0,"rootIndex":0,"root":0,"id":0,"pid":0,"index":0,"order":0},"score":{"type":"1","n":1,"unit":1,"full":1},"cid":"$compid","name":"1","page":"$pageid","order":0,"tag":"$type","learningObjective":[],"type":"$type","pageNumber":1,"hasScoring":"true"}';
		$structure = '{"version":"0.0.1","book":{"title":$title,"chapters":[{"pageCount":1,"title":"chapter 1","pages":[{"pageNumber":1,"id":"$pageid","title":"page 1","components":[$component]}],"docID":"$bookid","id":"$bookid","info":$info,"url":"item:/checksum/0/dirt/0/draft/0/id/$bookid/len/91/time/$time/uid/$uid/version/65535"}]}}';
		$componentStructure = str_replace('$type', $file_type, $componentStructure);
		$info = '{"url":"' . $url . '"}';
		$structure = str_replace('$pageid', $pageid, $structure);
		$structure = str_replace('$info', $info, $structure);
		$structure = str_replace('$compid', $compid, $structure);
		$structure = str_replace('$title', json_encode($row->title, JSON_UNESCAPED_UNICODE), $structure);
		$structure = str_replace('$time', time(), $structure);
		$structure = str_replace('$uid', $this->account['uid'], $structure);
		$structure = str_replace('$bookid', $entry_id, $structure);
		$structure = str_replace('$component', $componentStructure, $structure);
		$this->db->insert('ro_book_structure', ['unique_id' => 'ro_entries/' . $entry_id, 'structure' => $structure, 'score' => 0, 'question_count' => 1, 'entry_id' => $entry_id, 'entry_type' => 'ro_entries']);
	}

	public function getFileLink($entry_id)
	{
		$rows = $this->fetch_rows("SELECT * FROM ro_entry_detail WHERE entry_id=? LIMIT 1", [$entry_id]);
		return ['row' => sizeof($rows) > 0 ? $rows[0] : null];
	}

	private function build_asset($old_path, $local_path, $prefix)
	{
		if ($old_path != $local_path) {
			if ($local_path && is_file($local_path)) {
				$this->load->library("storage");
				$filename = basename($local_path);
				$target_file = "$prefix$filename";
				if ($old_path) {
					$this->storage->delete_file($old_path);
				}
				if ($this->storage->upload_file($local_path, $target_file, null)) {
					return $target_file;
				}
			}
		}
		return $old_path;
	}

	private function create_prefix($school_id, $prefix = 'file')
	{
		$date_string = date('Y/m/d');
		$time_string = date('His');
		$url = "$prefix/$date_string/school($school_id)/time($time_string)-";
		return $url;
	}

	public function get_entry_details($entry_id = 0)
	{
		$row = $this->fetch_row("SELECT * FROM ro_entry_detail WHERE entry_id=?", [$entry_id]);
		if (is_array($row) && sizeof($row) == 0) {
			$row = null;
		}
		return ['row' => $row, 'v' => 2];
	}

	public function add_personal_root()
	{
		$uid = $this->account['uid'];
		$school_id = $this->account['school_id'];
		$time = time();
		$rows = $this->fetch_rows("SELECT * FROM ro_entries WHERE type='my_folder' AND owner=? AND uid=? AND school_id=? LIMIT 1", [$uid, $uid, $school_id]);
		if (sizeof($rows) == 0) {
			$this->db->query("INSERT INTO ro_entries (pid,owner,title,type,time,active,uid,children,school_id,is_private) VALUES (0,$uid,'本機檔案','my_folder',$time,1,$uid,1,$school_id,1)");
			$row = $this->fetch_row("SELECT * FROM ro_entries WHERE type='my_folder' AND owner=? AND uid=? AND school_id=?", [$uid, $uid, $school_id]);
			return ['personal_root' => $row];
		} else {
			return ['personal_root' => $rows[0] ];
		}
	}

	public function set_private($entries_ids = '')
	{
		$entries_ids = preg_replace('/[^\d,]/', '', $entries_ids);
		$this->db->query("UPDATE ro_entries SET is_private= 1 WHERE id IN ($entries_ids)");
		return ['id' => $entries_ids];
	}
	public function get_student_data($tag_id)
	{
		$member_info = $this->fetch_rows("
				SELECT a.uid,e.classno,d.url,e.cname,e.ename,e.gender,f.title,e.display_name from ro_groups a

				LEFT JOIN `ro_profile_pic` c ON c.uid = a.uid AND c.active = 1
            	LEFT JOIN `ro_assets` d ON a.uid = d.uid AND d.id = SUBSTR(c.m,10,POSITION('/check' IN c.m) - 10)
            	LEFT JOIN `member_info` e ON e.uid = a.uid
            	LEFT JOIN `ro_school_tags` f ON  a.tid = f.id
				
				WHERE a.tid = ? AND a.active = 1 AND e.user_role = 2 AND e.active = 1
		", [$tag_id]);
		foreach ($member_info as $member) {
			$member_tags = $this->fetch_rows("
				SELECT * from ro_school_tags a
				LEFT JOIN `ro_groups` b ON b.tid = a.id
				WHERE b.uid = ?
			", [$member->uid]);
			$member->tags = $member_tags;
		}
		$row = $this->fetch_row("SELECT school_year FROM ro_school_tags WHERE id=?", [$tag_id]);
		return ["members" => $member_info, 'school_year_id' => empty($row) ? null : $row->school_year];
	}

	public function get_book_share_data($entry_id)
	{
		$uid = $this->account['uid'];
		$school_id = $this->account['school_id'];
		$group_list = $this->fetch_rows("
		SELECT * from ro_school_tags WHERE active = '1' AND id IN
		(SELECT linkage as id from ro_book_share_assignee WHERE bsid IN
			(SELECT id as bsid From ro_book_share WHERE type = 'exam' AND id IN  
				(SELECT bsid as id FROM ro_book_share_book WHERE entry_id = ? AND type='ro_entries')
			)
		)
		", [$entry_id]);

		// $all_group = $this->fetch_rows("
		// 	Select * from ro_school_tags Where active = '1' AND rid = ? AND type = '3' AND school_year IN 
		// 		(Select id from ro_school_year where active = '1' AND school_id = ?)
		// ",[$school_id,$school_id]);

		$members = [];
		foreach ($group_list as $group) {
			$tag_id = $group->id;
			$member_info = $this->fetch_rows("
				SELECT a.uid,d.url,e.cname,e.ename,e.gender,f.title from ro_groups a

				LEFT JOIN `ro_profile_pic` c ON c.uid = a.uid AND c.active = 1
            	LEFT JOIN `ro_assets` d ON a.uid = d.uid AND d.id = SUBSTR(c.m,10,POSITION('/check' IN c.m) - 10)
            	LEFT JOIN `member_info` e ON e.uid = a.uid
            	LEFT JOIN `ro_school_tags` f ON  a.tid = f.id
				
				WHERE a.tid = ? AND a.active = 1 AND e.user_role = 2
			", [$tag_id]);
			$members["$tag_id"] = $member_info;
		}

		return ['data' => $group_list, "members" => $members];
	}
	public function get_doc_id($book_id)
	{
		$school_id = $this->account['school_id'];
		$doc_id = $this->fetch_rows("
			SELECT id from ro_entries where pid = ? And school_id = ?
		", [$book_id, $school_id]);
		// $bsid = $this->fetch_rows("

		// 	SELECT bsid as id FROM ro_book_share_book WHERE entry_id = ? AND type='ro_entries'
		// ",[$book_id]);
		return ['doc_id' => $doc_id];
	}

	public function get_student_result_for_pdf_paper($bsid, $book_id)
	{
		$rows = $this->fetch_rows("
			SELECT a.uid, a.id, b.pid, b.data, b.result, b.cid, b.bid
			FROM ro_book_share_submission a
			INNER JOIN ro_book_share_submission_data b ON a.id = b.sid AND b.active = 1 AND b.tag = 'ExamPaper'
			WHERE a.bsid = ? AND a.book_id = ?
			ORDER BY b.id DESC
		", [$bsid, $book_id]);
		$pdfs = $this->fetch_rows("
			SELECT a.submit_index, b.url, a.i AS pdf_index, a.as AS asset_id, a.r
			FROM ro_ans_asset a
			INNER JOIN ro_assets b ON a.as = b.id
			where a = ? and a.active = 1
			ORDER BY a.as ASC
			", [$bsid]);
		$students = $this->fetch_rows("
			SELECT c.uid, CONCAT(c.first_name,' ',c.last_name) AS ename, 
			concat(c.c_last_name, c.c_first_name) AS cname 
			FROM ro_book_share_assignee a
			INNER JOIN ro_groups b ON a.linkage = b.tid AND a.type='tag' AND b.active = 1
			INNER JOIN member_info c ON b.uid = c.uid AND c.user_role = 2 AND c.active = 1
			WHERE a.bsid = ?
		", [$bsid]);
		$filenameRow = $this->fetch_row("SELECT field_value FROM ro_book_share_extended WHERE bsid = ? AND field_name = 'student_pdf_filename' LIMIT 1", [$bsid]);
		$student_pdf_filename = empty($filenameRow) ? null : $filenameRow->field_value;
		return ['v' => 2, 'pdfs' => $pdfs, 'student_result_map' => $rows, 'students' => $students, 'student_pdf_filename' => $student_pdf_filename];
	}

	public function clear_all_submit_for_pdf_paper($bsid, $student_have_deleted_img = [])
	{
		return ['code' => 1, 'deleted' => 0];
		if (sizeOf($student_have_deleted_img) == 0 && false) {
			return ['code' => 1, 'deleted' => 0];
		}
		$uids = implode(",", $student_have_deleted_img);
		$rows = $this->fetch_rows("SELECT id FROM ro_book_share_submission WHERE bsid = ?", [$bsid]);
		$deleted = 0;
		if ($rows) {
			foreach ($rows as $row) {
				$this->db->query("DELETE FROM ro_book_share_submission_data WHERE sid = ?", [$row->id]);
				$this->db->query("DELETE FROM ro_book_share_submission WHERE id = ?", [$row->id]);
				$deleted++;
			}
		}
		return ['code' => 1, 'deleted' => $deleted];
	}

	public function clear_all_submit_for_pdf_paper_new($bsid, $book_id, $deleted_pages = [])
	{

		/**
		 * 
		 * $pages = [ ["uid"=>1, "pid"=>"ABC-DEF","keepData"=> true] ,  ["uid"=>1, "pid"=>"ABC-DEF","keepData"=> true]  ]
		 * 
		 */
		$this->load->library('sails');
		$delete_count = 0;
		$count = count($deleted_pages);
		if ($count == 0) {
			return ['code' => 1, 'deleted' => $delete_count];
		}

		foreach ($deleted_pages as $page) {
			$uid = $page->uid;
			$pid = $page->pid;
			// ["uid"=>1, "pid"=>"ABC-DEF","keepData"=> true]
			$submissions = $this->sails->fetch_rows(
				"SELECT id FROM ro_book_share_submission 
				WHERE bsid = ? and book_id = ? and uid = ? AND `index` IN(0,1)",
				[$bsid, $book_id, $uid]
			);
			if (count($submissions) > 0) {
				$sub_id_array = [];
				foreach ($submissions as $sub) {
					$sub_id_array[] = $sub->id;
				}
				$sub_string = implode(",", $sub_id_array);
				$delete_count++;
				if (isset($page->keepData) && $page->keepData === true) {
					$this->db->query(
						"UPDATE `ro_book_share_submission_data` 
							SET active = 0  
							WHERE 
								sid IN($sub_string) AND
								pid = ? AND
								tag = 'ExamPaper' AND 
								active = 1",
						[
							$page->pid
						]
					);
				} else {
					header("line-$uid-$pid:$sub_string");
					$this->db->query(
						"UPDATE `ro_book_share_submission_data` 
							SET active = 0  
							WHERE 
								sid IN($sub_string) AND
								pid = ? AND
								active = 1",
						[
							$page->pid
						]
					);
				}
			}
		}

		return [
			'code' => 1, 'deleted' => $delete_count
		];



		///////////////// original code
		/**
		 * 
		 * $pages = [ ["uid"=>1, "pid"=>"ABC-DEF","keepData"=> true] ,  ["uid"=>1, "pid"=>"ABC-DEF","keepData"=> true]  ]
		 * 
		 */
		$this->load->library('sails');
		$delete_count = 0;
		$count = count($deleted_pages);
		if ($count == 0) {
			return ['code' => 1, 'deleted' => $delete_count];
		}

		$uid_array = [];
		$uid_map = [];
		foreach ($deleted_pages as $page) {
			$uid = $page->uid;
			if (!isset($uid_map[$uid])) {
				$uid_array[] = $page->uid;
				$uid_map[$uid] = [
					"uid" => $uid,
					"pages" => []
				];
			}
			$uid_map[$uid]["pages"][] = $page->pid;
		}
		$parameters = [[$bsid, $book_id]];
		foreach ($uid_map as $uid => $obj) {
			$pages = $obj["pages"];
			$page_count = count($pages);
			$q =  str_pad("", $page_count * 2 - 1, "?,");
			$filters[] = "(uid = ? AND pid IN($q))";
			$parameters[] = [$uid];
			$parameters[] = $pages;
		}

		$flatten_parameters = array_merge(...$parameters);

		// $uid_string = implode(",", $uid_array);
		$filter_string = "(" . implode(" OR ", $filters) . ")";

		$records = $this->sails->fetch_rows(
			$query = "
			SELECT 
			sub.uid, data.id, data.sid, data.tag, data.data, data.result, data.active, data.pid
			FROM ro_book_share_submission sub 
			INNER JOIN ro_book_share_submission_data data ON data.sid = sub.id
			WHERE 
			sub.bsid = ? AND sub.book_id = ? AND $filter_string AND 
			sub.`index` IN(0, 1) AND 
			data.tag = 'ExamPaper'
			AND data.active = 1
			",
			$flatten_parameters
		);

		// slow update
		foreach ($records as $record) {
			$result = $record->result;
			if ($result === "") {
				$result = (object)["status" => "absent"];
			} else if ($result === null) {
				$result = (object)["status" => "absent"];
			} else {
				$result = json_decode($result);
				if ($result) {
					$result->status = "absent";
				} else {
					$result = (object)["status" => "absent"];
				}
			}
			$keep_data = null;
			foreach ($deleted_pages as $dp) {
				if ($dp->uid == $record->uid && $dp->pid == $record->pid) {
					$keep_data = $dp->keepData ? 1 : 0;
				}
			}
			if ($keep_data) {
				$this->db->query(
					"UPDATE ro_book_share_submission_data 
					SET 
					result = ?, active = 0 
					WHERE id = ? AND sid = ?",
					[json_encode($result), $record->id, $record->sid]
				);
			} else {
				$result = (object)["status" => "absent"];
				$this->db->query(
					"UPDATE ro_book_share_submission_data 
					SET 
					result = ?, active = 0 
					WHERE id = ? AND sid = ?",
					[json_encode($result), $record->id, $record->sid]
				);
			}
		}
		$delete_count = count($records);
		return [
			'code' => 1, 'deleted' => $delete_count,
			"query" => $query, "parameters" => $flatten_parameters
		];
	}

	// public function get_role_operation()
	// {	
	// 	$operation_list = [];
	// 	$school_id = $this->account["school_id"];
	// 	$uid = $this->account["uid"];
	// 	$role_id_list = $this->fetch_rows("
	// 		SELECT role_id from ro_user_role 
	// 		Where uid = $uid AND school_id = $school_id
	// 	", []);
	// 	if (count($role_id_list) == 0) {
	// 		return ['operation_list' => $operation_list];
	// 	}
	// 	$roleIds = array_column($role_id_list, 'role_id');
	// 	$role_string = implode(',', $roleIds);
	// 	$operation_id_list = $this->fetch_rows("
	// 		SELECT operation_id from ro_school_setting_role_operation_mapping
	// 		Where school_id = $school_id AND role_id in ($role_string)
	// 	", []);
	// 	if (count($operation_id_list) == 0) {
	// 		return ['operation_list' => $operation_list];
	// 	}
	// 	$operationIds = array_column($operation_id_list, 'operation_id');
	// 	$operation_string = implode(',', $operationIds);
	// 	$operation_list =  $this->fetch_rows("
	// 		SELECT * from ro_school_setting_role_operation
	// 		Where id in ($operation_string)
	// 	", []);
	
	// 	return ['operation_list' => $operation_list];
	// }

    public function transform_qti($book_id){
        if (empty($this->account)) {
			return ['code' => 11, 'msg' => 'LOGIN_REQUIRED'];
		}
        if ($this->account['user_role'] < 3){
            return ['msg'=> 'unauthorized'];
        }
        $row = $this->fetch_row("
            SELECT b.content
            FROM ro_entries a 
            INNER JOIN ro_item b ON a.id = b.id
            WHERE a.type = 'assessment' AND a.id = ? LIMIT 1", [$book_id]);
        if (empty($row)){
            return ['msg'=> 'no data'];
        }
        $json = $row->content;
        $res = null;

        // $endpoint = 'https://okagpt.openai.azure.com/openai/deployments/gpt-35-turbo/chat/completions?api-version=2023-05-15';
		// $curl = new Curl();
		// $curl->output_format = "raw";
		// $curl_creator = new CurlCreator();
		// $ch = $curl_creator->create_curl_handler(
		// 	[
		// 		"header" => [
		// 			"Content-Type" => "application/json",
		// 		],
		// 		"url" => $endpoint,
		// 		"data" => [
		// 			"json" => $content
		// 		]
		// 	]
		// );
		// $o = $curl->auto_retry_exec($ch, 3);
        // $res = $o->body;

        return ['json'=> $json, 'res'=> $res, 'v'=> 1];
    }

}
