<?php
App::uses('AppController', 'Controller');
App::uses('Xml', 'Utility');

/**
 * Servers Controller
 *
 * @property Server $Server
 *
 * @throws ConfigureException // TODO Exception
 */
class ServersController extends AppController {

	public $components = array('Security' ,'RequestHandler');	// XXX ACL component

	public $paginate = array(
			'limit' => 60,
			'maxLimit' => 9999, // LATER we will bump here on a problem once we have more than 9999 events
			'order' => array(
					'Server.url' => 'ASC'
			)
	);

	public $uses = array('Server', 'Event');

	public function beforeFilter() {
		parent::beforeFilter();

		// permit reuse of CSRF tokens on some pages.
		switch ($this->request->params['action']) {
			case 'push':
			case 'pull':
				$this->Security->csrfUseOnce = false;
		}
	}

/**
 * index method
 *
 * @return void
 */
	public function index() {
		$this->Server->recursive = 0;
		if ($this->_isSiteAdmin()) {
			$this->paginate = array(
							'conditions' => array(),
			);
		} else {
			if (!$this->userRole['perm_sync'] && !$this->userRole['perm_admin']) $this->redirect(array('controller' => 'events', 'action' => 'index'));
			$conditions['Server.org LIKE'] = $this->Auth->user('org');
			$this->paginate = array(
					'conditions' => array($conditions),
			);
		}
		$this->set('servers', $this->paginate());
	}

/**
 * add method
 *
 * @return void
 */
	public function add() {
		if (!$this->_isAdmin()) $this->redirect(array('controller' => 'servers', 'action' => 'index'));
		if ($this->request->is('post')) {
			// force check userid and orgname to be from yourself
			$this->request->data['Server']['org'] = $this->Auth->user('org');
			if ($this->Server->save($this->request->data)) {
				if (isset($this->request->data['Server']['submitted_cert'])) {
					$this->__saveCert($this->request->data, $this->Server->id);
				}
				$this->Session->setFlash(__('The server has been saved'));
				$this->redirect(array('action' => 'index'));
			} else {
				$this->Session->setFlash(__('The server could not be saved. Please, try again.'));
			}
		}
	}

/**
 * edit method
 *
 * @param string $id
 * @return void
 * @throws NotFoundException
 */
	public function edit($id = null) {
		$this->Server->id = $id;
		if (!$this->Server->exists()) {
			throw new NotFoundException(__('Invalid server'));
		}
		$s = $this->Server->read(null, $id);
		if (!$this->_isSiteAdmin() && !($s['Server']['org'] == $this->Auth->user('org') && $this->_isAdmin())) $this->redirect(array('controller' => 'servers', 'action' => 'index'));
		if ($this->request->is('post') || $this->request->is('put')) {
			// say what fields are to be updated
			$fieldList = array('id', 'url', 'push', 'pull', 'organization', 'self_signed', 'cert_file');
			$this->request->data['Server']['id'] = $id;
			if ("" != $this->request->data['Server']['authkey'])
				$fieldList[] = 'authkey';
			// Save the data
			if ($this->Server->save($this->request->data, true, $fieldList)) {
				if (isset($this->request->data['Server']['submitted_cert']) && $this->request->data['Server']['submitted_cert']['size'] != 0) {
					$this->__saveCert($this->request->data, $this->Server->id);
				}
				$this->Session->setFlash(__('The server has been saved'));
				$this->redirect(array('action' => 'index'));
			} else {
				$this->Session->setFlash(__('The server could not be saved. Please, try again.'));
			}
		} else {
			$this->Server->read(null, $id);
			$this->Server->set('authkey', '');
			$this->request->data = $this->Server->data;
		}
	}

/**
 * delete method
 *
 * @param string $id
 * @return void
 * @throws MethodNotAllowedException
 * @throws NotFoundException
 */
	public function delete($id = null) {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		$this->Server->id = $id;
		if (!$this->Server->exists()) {
			throw new NotFoundException(__('Invalid server'));
		}
		$s = $this->Server->read(null, $id);
		if (!$this->_isSiteAdmin() && !($s['Server']['org'] == $this->Auth->user('org') && $this->_isAdmin())) $this->redirect(array('controller' => 'servers', 'action' => 'index'));
		if ($this->Server->delete()) {
			$this->Session->setFlash(__('Server deleted'));
			$this->redirect(array('action' => 'index'));
		}
		$this->Session->setFlash(__('Server was not deleted'));
		$this->redirect(array('action' => 'index'));
	}

	/**
	 * Pull one or more events with attributes from a remote instance.
	 * Set $technique to
	 * 		full - download everything
	 * 		incremental - only new events
	 * 		<int>	- specific id of the event to pull
	 * For example to download event 10 from server 2 to /servers/pull/2/5
	 * @param int $id The id of the server
	 * @param unknown_type $technique
	 * @throws MethodNotAllowedException
	 * @throws NotFoundException
	 */
	public function pull($id = null, $technique=false) {
		$this->Server->id = $id;
		if (!$this->Server->exists()) {
			throw new NotFoundException(__('Invalid server'));
		}
		$s = $this->Server->read(null, $id);
		if (!$this->_isSiteAdmin() && !($s['Server']['org'] == $this->Auth->user('org') && $this->_isAdmin())) $this->redirect(array('controller' => 'servers', 'action' => 'index'));
		$this->Server->id = $id;
		if (!$this->Server->exists()) {
			throw new NotFoundException(__('Invalid server'));
		}

		if (false == $this->Server->data['Server']['pull']) {
			$this->Session->setFlash(__('Pull setting not enabled for this server.'));
			$this->redirect(array('action' => 'index'));
		}
		if (!Configure::read('MISP.background_jobs')) {
			$result = $this->Server->pull($this->Auth->user(), $id, $technique, $s);
			
			// error codes
			if (is_numeric($result)) {
				switch ($result) {
					case '1' :
						$this->Session->setFlash(__('Not authorised. This is either due to an invalid auth key, or due to the sync user not having authentication permissions enabled on the remote server.'));
						$this->redirect(array('action' => 'index'));
						break;
					case '2' :
						$this->Session->setFlash($eventIds);
						$this->redirect(array('action' => 'index'));
						break;
					case '3' :
						throw new NotFoundException('Sorry, this is not yet implemented');
						break;
					case '4' :
						$this->redirect(array('action' => 'index'));
						break;
						
				}
			} else {
				$this->set('successes', $result[0]);
				$this->set('fails', $result[1]);
				$this->set('pulledProposals', $result[2]);
				$this->set('lastpulledid', $result[3]);
			}
		} else {
			$this->loadModel('Job');
			$this->Job->create();
			$data = array(
					'worker' => 'default',
					'job_type' => 'pull',
					'job_input' => 'Server: ' . $id,
					'status' => 0,
					'retries' => 0,
					'org' => $this->Auth->user('org'),
					'message' => 'Pulling.',
			);
			$this->Job->save($data);
			$jobId = $this->Job->id;
			$process_id = CakeResque::enqueue(
					'default',
					'ServerShell',
					array('pull', $this->Auth->user('id'), $id, $technique, $jobId)
			);
			$this->Job->saveField('process_id', $process_id);
			$this->Session->setFlash('Pull queued for background execution.');
			$this->redirect(array('action' => 'index'));
		}
	}

	public function push($id = null, $technique=false) {
		$this->Server->id = $id;
		if (!$this->Server->exists()) {
			throw new NotFoundException(__('Invalid server'));
		}
		$s = $this->Server->read(null, $id);
		if (!$this->_isSiteAdmin() && !($s['Server']['org'] == $this->Auth->user('org') && $this->_isAdmin())) $this->redirect(array('controller' => 'servers', 'action' => 'index'));
		if (!Configure::read('MISP.background_jobs')) {
			$server = $this->Server->read(null, $id);
			App::uses('SyncTool', 'Tools');
			$syncTool = new SyncTool();
			$HttpSocket = $syncTool->setupHttpSocket($server);
			$result = $this->Server->push($id, $technique, false, $HttpSocket);
			$this->set('successes', $result[0]);
			$this->set('fails', $result[1]);
		} else {
			$this->loadModel('Job');
			$this->Job->create();
			$data = array(
					'worker' => 'default',
					'job_type' => 'push',
					'job_input' => 'Server: ' . $id,
					'status' => 0,
					'retries' => 0,
					'org' => $this->Auth->user('org'),
					'message' => 'Pushing.',
			);
			$this->Job->save($data);
			$jobId = $this->Job->id;
			$process_id = CakeResque::enqueue(
					'default',
					'ServerShell',
					array('push', $id, $technique, $jobId)
			);
			$this->Job->saveField('process_id', $process_id);
			$this->Session->setFlash('Push queued for background execution.');
			$this->redirect(array('action' => 'index'));
		}
	}
	
	public function __saveCert($server, $id) {
		$ext = '';
		App::uses('File', 'Utility');
		App::uses('Folder', 'Utility');
		$file = new File($server['Server']['submitted_cert']['name']);
		$ext = $file->ext();
		if (($ext != 'pem') || !$server['Server']['submitted_cert']['size'] > 0) {
			$this->Session->setFlash('Incorrect extension of empty file.');
			$this->redirect(array('action' => 'index'));
		}
		$pemData = fread(fopen($server['Server']['submitted_cert']['tmp_name'], "r"),
				$server['Server']['submitted_cert']['size']);
		$destpath = APP . "files" . DS . "certs" . DS;
		$dir = new Folder(APP . "files" . DS . "certs", true);
		if (!preg_match('@^[\w-,\s,\.]+\.[A-Za-z0-9_]{2,4}$@', $server['Server']['submitted_cert']['name'])) throw new Exception ('Filename not allowed');
		$pemfile = new File ($destpath . $id . '.' . $ext);
		$result = $pemfile->write($pemData); 
		$s = $this->Server->read(null, $id);
		$s['Server']['cert_file'] = $s['Server']['id'] . '.' . $ext;
		if ($result) $this->Server->save($s);
	}
	
	public function serverSettings($tab=false) {
		if (!$this->_isSiteAdmin()) throw new MethodNotAllowedException();
		if ($this->request->is('Get')) {
			$tabs = array(
					'MISP' => array('count' => 0, 'errors' => 0, 'severity' => 5),
					'GnuPG' => array('count' => 0, 'errors' => 0, 'severity' => 5),
					'Security' => array('count' => 0, 'errors' => 0, 'severity' => 5),
					'misc' => array('count' => 0, 'errors' => 0, 'severity' => 5)
			);
			$results = $this->Server->serverSettingsRead();
			$issues = array(	
				'errors' => array(
						0 => array(
								'value' => 0,
								'description' => 'MISP will not operate correctly or will be unsecure until these issues are resolved.'
						), 
						1 => array(
								'value' => 0,
								'description' => 'Some of the features of MISP cannot be utilised until these issues are resolved.'
						), 
						2 => array(
								'value' => 0,
								'description' => 'There are some optional tweaks that could be done to improve the looks of your MISP instance.'
						),
				),
				'deprecated' => array(),
				'overallHealth' => 3, 
			);
			$dumpResults = array();
			foreach ($results as $k => $result) {
				if ($result['level'] == 3) $issues['deprecated']++;
				$tabs[$result['tab']]['count']++;
				if (isset($result['error']) && $result['level'] < 3) {
					$issues['errors'][$result['level']]['value']++;
					if ($result['level'] < $issues['overallHealth']) $issues['overallHealth'] = $result['level'];
					$tabs[$result['tab']]['errors']++;
					if ($result['level'] < $tabs[$result['tab']]['severity']) $tabs[$result['tab']]['severity'] = $result['level'];
				}
				$dumpResults[] = $result;
				if ($result['tab'] != $tab) unset($results[$k]);
			}
			// Diagnostics portion
			$diagnostic_errors = 0;
			App::uses('File', 'Utility');
			App::uses('Folder', 'Utility');
			
			// check writeable directories
			$writeableDirs = array(
					'tmp' => 0, 'files' => 0, 'scripts' . DS . 'tmp' => 0,
					'tmp' . DS . 'csv_all' => 0, 'tmp' . DS . 'csv_sig' => 0, 'tmp' . DS . 'md5' => 0, 'tmp' . DS . 'sha1' => 0,
					'tmp' . DS . 'snort' => 0, 'tmp' . DS . 'suricata' => 0, 'tmp' . DS . 'text' => 0, 'tmp' . DS . 'xml' => 0,
					'tmp' . DS . 'files' => 0, 'tmp' . DS . 'logs' => 0,
			);
			foreach ($writeableDirs as $path => &$error) {
				$dir = new Folder(APP . DS . $path);
				if (is_null($dir->path)) $error = 1;
				$file = new File (APP . DS . $path . DS . 'test.txt', true);
				if ($error == 0 && !$file->write('test')) $error = 2;
				if ($error != 0) $diagnostic_errors++;
				$file->delete();
				$file->close();
			}
			$this->set('writeableDirs', $writeableDirs);
			
			// check if the STIX and Cybox libraries are working using the test script stixtest.py
			$stix = shell_exec('python ' . APP . 'files' . DS . 'scripts' . DS . 'stixtest.py');
			$stix = json_decode($stix)->success;
			$this->set('stix', $stix);
			if ($stix == 0) $diagnostic_errors++;

			// if GPG is set up in the settings, try to encrypt a test message
			$gpgStatus = 0;
			if (Configure::read('GnuPG.email') && Configure::read('GnuPG.homedir')) {
				$continue = true;
				try {
					require_once 'Crypt/GPG.php';
					$gpg = new Crypt_GPG(array('homedir' => Configure::read('GnuPG.homedir')));
					$key = $gpg->addEncryptKey(Configure::read('GnuPG.email'));
				} catch (Exception $e) {
					$gpgStatus = 2;
					$continue = false;		
				}
				if ($continue) {
					try {
						$gpgStatus = 0;
						$enc = $gpg->encrypt('test', true);
					} catch (Exception $e){
						$gpgStatus = 3;
					}
				}
			} else {
				$gpgStatus = 1;
			}
			if ($gpgStatus != 0) $diagnostic_errors++;
			
			$this->set('gpgStatus', $gpgStatus);
			$this->set('diagnostic_errors', $diagnostic_errors);
			$this->set('tab', $tab);
			$this->set('tabs', $tabs);
			$this->set('issues', $issues);
			$this->set('finalSettings', $results);
			$dump = array('gpgStatus' => $gpgStatus, 'stix' => $stix, 'writeableDirs' => $writeableDirs, 'finalSettings' => $dumpResults);
			$this->set('dump', json_encode($dump));
			$priorities = array(0 => 'Critical', 1 => 'Recommended', 2 => 'Optional', 3 => 'Deprecated');
			$priorityErrorColours = array(0 => 'red', 1 => 'yellow', 2 => 'green');
			$this->set('priorities', $priorities);
			$this->set('priorityErrorColours', $priorityErrorColours);
		}
	}
	
	public function serverSettingsEdit($setting, $id, $forceSave = false) {
		if (!$this->_isSiteAdmin()) throw new MethodNotAllowedException();
		if (!isset($setting) || !isset($id)) throw new MethodNotAllowedException();
		$this->set('id', $id);
		$relevantSettings = (array_intersect_key(Configure::read(), $this->Server->serverSettings));
		$found = null;
		foreach ($this->Server->serverSettings as $k => $s) {
			if (isset($s['branch'])) {
				foreach ($s as $ek => $es) {
					if ($ek != 'branch') {
						if ($setting == $k . '.' . $ek) {
							$found = $es;
							continue 2;
						}
					}
				}
			} else {
				if ($setting == $k) {
					$found = $s;
					continue;
				}
			}
		}
		if ($this->request->is('get')) {
			if ($found != null) {
				$found['value'] = Configure::read($setting);
				$found['setting'] = $setting;
			}
			$this->set('setting', $found);
			$this->render('ajax/server_settings_edit');
		}
		if ($this->request->is('post')) {
			if ($found['type'] == 'boolean') {
				$this->request->data['Server']['value'] = ($this->request->data['Server']['value'] ? true : false);
			}
			if ($found['type'] == 'numeric') {
				$this->request->data['Server']['value'] = intval($this->request->data['Server']['value']);
			}
			$testResult = $this->Server->{$found['test']}($this->request->data['Server']['value']);
			if (!$forceSave && $testResult !== true) {
				if ($testResult === false) $errorMessage = $found['errorMessage'];
				else $errorMessage = $testResult;
				return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => $errorMessage)),'status'=>200));
			} else {
				$this->Server->serverSettingsSaveValue($setting, $this->request->data['Server']['value']);
				$this->autoRender = false;
				return new CakeResponse(array('body'=> json_encode(array('saved' => true, 'success' => 'Field updated.')),'status'=>200));
			}
		}
	}
}
