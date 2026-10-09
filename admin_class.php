<?php
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}
ini_set('display_errors', 0);

Class Action {
	private $db;

	// Columns each form is allowed to write. Anything else in $_POST is ignored.
	private const USER_COLS     = ['firstname', 'lastname', 'email', 'type'];
	private const PROJECT_COLS  = ['name', 'description', 'status', 'start_date', 'end_date', 'manager_id'];
	private const TASK_COLS     = ['project_id', 'task', 'description', 'status'];
	private const PROGRESS_COLS = ['project_id', 'task_id', 'comment', 'subject', 'date', 'start_time', 'end_time'];
	private const SETTINGS_COLS = ['name', 'email', 'contact', 'address'];
	private const IMG_EXT       = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

	public function __construct() {
		ob_start();
		include 'db_connect.php';
		$this->db = $conn;
	}
	function __destruct() {
		$this->db->close();
		ob_end_flush();
	}

	/* ---------- helpers ---------- */

	private function post($k, $default = '') {
		return isset($_POST[$k]) && !is_array($_POST[$k]) ? trim($_POST[$k]) : $default;
	}
	private function postId() {
		return isset($_POST['id']) ? (int)$_POST['id'] : 0;
	}
	private function isAdmin() {
		return isset($_SESSION['login_type']) && (int)$_SESSION['login_type'] === 1;
	}

	/** Run a prepared statement; all params bound as strings (MySQL casts as needed). */
	private function run($sql, array $params = []) {
		$stmt = $this->db->prepare($sql);
		if (!$stmt) return false;
		if ($params) {
			$params = array_values($params);
			$stmt->bind_param(str_repeat('s', count($params)), ...$params);
		}
		if (!$stmt->execute()) return false;
		$res = $stmt->get_result();
		return $res === false ? true : $res;
	}

	/** INSERT or UPDATE using only whitelisted columns. */
	private function upsert($table, array $data, $id = 0, $extraWhere = '', array $extraParams = []) {
		if (!$data) return false;
		$sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
		if ($id > 0) {
			return $this->run("UPDATE `$table` SET $sets WHERE id = ? $extraWhere",
				array_merge(array_values($data), [$id], $extraParams));
		}
		return $this->run("INSERT INTO `$table` SET $sets", array_values($data));
	}

	private function pick(array $cols) {
		$data = [];
		foreach ($cols as $c) {
			if (isset($_POST[$c]) && !is_array($_POST[$c])) $data[$c] = trim($_POST[$c]);
		}
		return $data;
	}

	/** Validate and store an uploaded image; returns the stored filename or null. */
	private function storeImage($field, $dir = 'assets/uploads/') {
		if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
		$ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, self::IMG_EXT, true)) return null;
		if (@getimagesize($_FILES[$field]['tmp_name']) === false) return null;
		$fname = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
		return move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $fname) ? $fname : null;
	}

	private function emailTaken($email, $id) {
		$r = $this->run("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id]);
		return $r && $r->num_rows > 0;
	}

	private function setUserSession(array $row) {
		session_regenerate_id(true);
		foreach ($row as $key => $value) {
			if ($key != 'password' && !is_numeric($key))
				$_SESSION['login_' . $key] = $value;
		}
	}

	/* ---------- auth ---------- */

	function login(){
		$email = $this->post('email');
		$password = $this->post('password');
		$r = $this->run("SELECT *, concat(firstname,' ',lastname) as name FROM users WHERE email = ? LIMIT 1", [$email]);
		if (!$r || $r->num_rows === 0) return 2;
		$row = $r->fetch_assoc();
		$hash = $row['password'];
		$ok = password_verify($password, $hash);
		// Legacy MD5 hashes: accept once, then upgrade to bcrypt.
		if (!$ok && strlen($hash) === 32 && hash_equals($hash, md5($password))) {
			$ok = true;
			$this->run("UPDATE users SET password = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
		}
		if (!$ok) return 2;
		$this->setUserSession($row);
		return 1;
	}
	function logout(){
		$_SESSION = [];
		session_destroy();
		header("location:login.php");
	}
	function login2(){
		$code = $this->post('student_code');
		$r = $this->run("SELECT *, concat(lastname,', ',firstname,' ',middlename) as name FROM students WHERE student_code = ?", [$code]);
		if ($r && $r->num_rows > 0) {
			session_regenerate_id(true);
			foreach ($r->fetch_assoc() as $key => $value) {
				if ($key != 'password') $_SESSION['rs_' . $key] = $value;
			}
			return 1;
		}
		return 3;
	}

	/* ---------- users ---------- */

	function save_user(){
		if (!$this->isAdmin()) return 0;
		$id = $this->postId();
		$data = $this->pick(self::USER_COLS);
		if (isset($data['type'])) $data['type'] = (string)max(1, min(3, (int)$data['type']));
		if ($this->emailTaken($data['email'] ?? '', $id)) return 2;
		$pw = $this->post('password');
		if ($pw !== '') $data['password'] = password_hash($pw, PASSWORD_DEFAULT);
		if ($f = $this->storeImage('img')) $data['avatar'] = $f;
		return $this->upsert('users', $data, $id) ? 1 : 0;
	}

	function signup(){
		// Self sign-up always creates a staff account; role cannot be chosen by the client.
		$data = $this->pick(['firstname', 'lastname', 'email']);
		$data['type'] = '3';
		$pw = $this->post('password');
		if ($pw === '' || ($data['email'] ?? '') === '') return 0;
		if ($this->emailTaken($data['email'], 0)) return 2;
		$data['password'] = password_hash($pw, PASSWORD_DEFAULT);
		if ($f = $this->storeImage('img')) $data['avatar'] = $f;
		if (!$this->upsert('users', $data)) return 0;
		$id = $this->db->insert_id;
		unset($data['password']);
		$this->setUserSession($data + ['id' => $id, 'name' => trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? ''))]);
		return 1;
	}

	function update_user(){
		if (!isset($_SESSION['login_id'])) return 0;
		$id = $this->postId();
		// Non-admins may only edit their own profile and cannot change their role.
		if (!$this->isAdmin()) {
			$id = (int)$_SESSION['login_id'];
			unset($_POST['type']);
		}
		if ($id <= 0) return 0;
		$data = $this->pick(self::USER_COLS);
		if (isset($data['type'])) $data['type'] = (string)max(1, min(3, (int)$data['type']));
		if ($this->emailTaken($data['email'] ?? '', $id)) return 2;
		$pw = $this->post('password');
		if ($pw !== '') $data['password'] = password_hash($pw, PASSWORD_DEFAULT);
		if ($f = $this->storeImage('img')) $data['avatar'] = $f;
		if (!$this->upsert('users', $data, $id)) return 0;
		if ($id === (int)$_SESSION['login_id']) {
			unset($data['password']);
			foreach ($data as $k => $v) $_SESSION['login_' . $k] = $v;
		}
		return 1;
	}

	function delete_user(){
		if (!$this->isAdmin()) return 0;
		$id = $this->postId();
		if ($id === (int)$_SESSION['login_id']) return 0;
		return $this->run("DELETE FROM users WHERE id = ?", [$id]) ? 1 : 0;
	}

	function save_system_settings(){
		if (!$this->isAdmin()) return 0;
		$data = $this->pick(self::SETTINGS_COLS);
		if ($f = $this->storeImage('cover', '../assets/uploads/')) $data['cover_img'] = $f;
		$chk = $this->run("SELECT id FROM system_settings LIMIT 1");
		$id = ($chk && $chk->num_rows > 0) ? (int)$chk->fetch_assoc()['id'] : 0;
		if (!$this->upsert('system_settings', $data, $id)) return 0;
		foreach ($data as $k => $v) $_SESSION['system'][$k] = $v;
		return 1;
	}

	function save_image(){
		if (!isset($_SESSION['login_id'])) return null;
		$fname = $this->storeImage('file');
		if (!$fname) return null;
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$path = explode('/', $_SERVER['PHP_SELF']);
		return $protocol . '://' . $_SERVER['HTTP_HOST'] . '/' . $path[1] . '/assets/uploads/' . $fname;
	}

	/* ---------- projects / tasks / progress ---------- */

	function save_project(){
		if (!isset($_SESSION['login_type']) || (int)$_SESSION['login_type'] > 2) return 0;
		$id = $this->postId();
		$data = $this->pick(self::PROJECT_COLS);
		if (isset($data['description'])) $data['description'] = htmlentities($data['description'], ENT_QUOTES);
		if (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
			$data['user_ids'] = implode(',', array_map('intval', $_POST['user_ids']));
		}
		return $this->upsert('project_list', $data, $id) ? 1 : 0;
	}
	function delete_project(){
		if (!$this->isAdmin()) return 0;
		return $this->run("DELETE FROM project_list WHERE id = ?", [$this->postId()]) ? 1 : 0;
	}
	function save_task(){
		if (!isset($_SESSION['login_type']) || (int)$_SESSION['login_type'] > 2) return 0;
		$id = $this->postId();
		$data = $this->pick(self::TASK_COLS);
		if (isset($data['description'])) $data['description'] = htmlentities($data['description'], ENT_QUOTES);
		return $this->upsert('task_list', $data, $id) ? 1 : 0;
	}
	function delete_task(){
		if (!isset($_SESSION['login_type']) || (int)$_SESSION['login_type'] > 2) return 0;
		return $this->run("DELETE FROM task_list WHERE id = ?", [$this->postId()]) ? 1 : 0;
	}
	function save_progress(){
		if (!isset($_SESSION['login_id'])) return 0;
		$id = $this->postId();
		$data = $this->pick(self::PROGRESS_COLS);
		if (isset($data['comment'])) $data['comment'] = htmlentities($data['comment'], ENT_QUOTES);
		$dur = abs(strtotime("2020-01-01 " . ($data['end_time'] ?? ''))) - abs(strtotime("2020-01-01 " . ($data['start_time'] ?? '')));
		$data['time_rendered'] = (string)($dur / 3600);
		if ($id > 0) {
			// Staff may only edit their own entries.
			$own = $this->isAdmin() ? '' : 'AND user_id = ?';
			$p = $this->isAdmin() ? [] : [(int)$_SESSION['login_id']];
			return $this->upsert('user_productivity', $data, $id, $own, $p) ? 1 : 0;
		}
		$data['user_id'] = (string)(int)$_SESSION['login_id'];
		return $this->upsert('user_productivity', $data) ? 1 : 0;
	}
	function delete_progress(){
		if (!isset($_SESSION['login_id'])) return 0;
		if ($this->isAdmin())
			return $this->run("DELETE FROM user_productivity WHERE id = ?", [$this->postId()]) ? 1 : 0;
		return $this->run("DELETE FROM user_productivity WHERE id = ? AND user_id = ?", [$this->postId(), (int)$_SESSION['login_id']]) ? 1 : 0;
	}
	function get_report(){
		if (!isset($_SESSION['login_id'])) return json_encode([]);
		$data = array();
		$get = $this->run("SELECT t.*, p.name as ticket_for FROM ticket_list t INNER JOIN pricing p ON p.id = t.pricing_id WHERE date(t.date_created) BETWEEN ? AND ? ORDER BY unix_timestamp(t.date_created) DESC",
			[$this->post('date_from'), $this->post('date_to')]);
		if (!$get || $get === true) return json_encode($data);
		while ($row = $get->fetch_assoc()) {
			$row['date_created'] = date("M d, Y", strtotime($row['date_created']));
			$row['name'] = ucwords($row['name']);
			$row['adult_price'] = number_format($row['adult_price'], 2);
			$row['child_price'] = number_format($row['child_price'], 2);
			$row['amount'] = number_format($row['amount'], 2);
			$data[] = $row;
		}
		return json_encode($data);
	}
}
