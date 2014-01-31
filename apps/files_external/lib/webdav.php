<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Files\Storage;

class DAV extends \OC\Files\Storage\Common{
	private $password;
	private $user;
	private $host;
	private $secure;
	private $root;
	private $certPath;
	private $ready;
	/**
	 * @var \Sabre_DAV_Client
	 */
	private $client;

	private static $tempFiles=array();

	public function __construct($params) {
		if (isset($params['host']) && isset($params['user']) && isset($params['password'])) {
			$host = $params['host'];
			//remove leading http[s], will be generated in createBaseUri()
			if (substr($host, 0, 8) == "https://") $host = substr($host, 8);
			else if (substr($host, 0, 7) == "http://") $host = substr($host, 7);
			$this->host=$host;
			$this->user=$params['user'];
			$this->password=$params['password'];
			if (isset($params['secure'])) {
				if (is_string($params['secure'])) {
					$this->secure = ($params['secure'] === 'true');
				} else {
					$this->secure = (bool)$params['secure'];
				}
			} else {
				$this->secure = false;
			}
			if ($this->secure === true) {
				$certPath=\OC_User::getHome(\OC_User::getUser()) . '/files_external/rootcerts.crt';
				if (file_exists($certPath)) {
					$this->certPath=$certPath;
				}
			}
			$this->root=isset($params['root'])?$params['root']:'/';
			if ( ! $this->root || $this->root[0]!='/') {
				$this->root='/'.$this->root;
			}
			if (substr($this->root, -1, 1)!='/') {
				$this->root.='/';
			}
		} else {
			throw new \Exception();
		}
	}

	private function init(){
		if($this->ready) {
			return;
		}
		$this->ready = true;

		$settings = array(
			'baseUri' => $this->createBaseUri(),
			'userName' => $this->user,
			'password' => $this->password,
		);

		$this->client = new \Sabre_DAV_Client($settings);

		if ($this->secure === true && $this->certPath) {
			$this->client->addTrustedCertificates($this->certPath);
		}
	}

	public function getId(){
		return 'webdav::' . $this->user . '@' . $this->host . '/' . $this->root;
	}

	protected function createBaseUri() {
		$baseUri='http';
		if ($this->secure) {
			$baseUri.='s';
		}
		$baseUri.='://'.$this->host.$this->root;
		return $baseUri;
	}

	public function mkdir($path) {
		$this->init();
		$path=$this->cleanPath($path);
		return $this->simpleResponse('MKCOL', $path, null, 201);
	}

	public function rmdir($path) {
		$this->init();
		$path=$this->cleanPath($path) . '/';
		// FIXME: some WebDAV impl return 403 when trying to DELETE
		// a non-empty folder
		return $this->simpleResponse('DELETE', $path, null, 204);
	}

	public function opendir($path) {
		$this->init();
		$path=$this->cleanPath($path);
		try {
			$response=$this->client->propfind($this->encodePath($path), array(), 1);
			$id=md5('webdav'.$this->root.$path);
			$content = array();
			$files=array_keys($response);
			array_shift($files);//the first entry is the current directory
			foreach ($files as $file) {
				$file = urldecode(basename($file));
				$content[]=$file;
			}
			\OC\Files\Stream\Dir::register($id, $content);
			return opendir('fakedir://'.$id);
		} catch(\Exception $e) {
			return false;
		}
	}

	public function filetype($path) {
		$this->init();
		$path=$this->cleanPath($path);
		try {
			$response=$this->client->propfind($this->encodePath($path), array('{DAV:}resourcetype'));
			$responseType = array();
			if (isset($response["{DAV:}resourcetype"])) {
				$responseType=$response["{DAV:}resourcetype"]->resourceType;
			}
			return (count($responseType)>0 and $responseType[0]=="{DAV:}collection")?'dir':'file';
		} catch(\Exception $e) {
			error_log($e->getMessage());
			\OCP\Util::writeLog("webdav client", \OCP\Util::sanitizeHTML($e->getMessage()), \OCP\Util::ERROR);
			return false;
		}
	}

	public function file_exists($path) {
		$this->init();
		$path=$this->cleanPath($path);
		try {
			$this->client->propfind($this->encodePath($path), array('{DAV:}resourcetype'));
			return true;//no 404 exception
		} catch(\Exception $e) {
			return false;
		}
	}

	public function unlink($path) {
		$this->init();
		return $this->simpleResponse('DELETE', $path, null, 204);
	}

	public function fopen($path, $mode) {
		$this->init();
		$path=$this->cleanPath($path);
		switch($mode) {
			case 'r':
			case 'rb':
				if ( ! $this->file_exists($path)) {
					return false;
				}
				//straight up curl instead of sabredav here, sabredav put's the entire get result in memory
				$curl = curl_init();
				$fp = fopen('php://temp', 'r+');
				curl_setopt($curl, CURLOPT_USERPWD, $this->user.':'.$this->password);
				curl_setopt($curl, CURLOPT_URL, $this->createBaseUri().$this->encodePath($path));
				curl_setopt($curl, CURLOPT_FILE, $fp);
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				if ($this->secure === true) {
					curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
					curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
					if($this->certPath){
						curl_setopt($curl, CURLOPT_CAINFO, $this->certPath);
					}
				}
				
				curl_exec ($curl);
				$statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				if ($statusCode !== 200) {
					\OCP\Util::writeLog("webdav client", 'curl GET ' . curl_getinfo($curl, CURLINFO_EFFECTIVE_URL) . ' returned status code ' . $statusCode, \OCP\Util::ERROR);
				}
				curl_close ($curl);
				rewind($fp);
				return $fp;
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				//emulate these
				if (strrpos($path, '.')!==false) {
					$ext=substr($path, strrpos($path, '.'));
				} else {
					$ext='';
				}
				$tmpFile = \OCP\Files::tmpFile($ext);
				\OC\Files\Stream\Close::registerCallback($tmpFile, array($this, 'writeBack'));
				if($this->file_exists($path)) {
					$this->getFile($path, $tmpFile);
				}
				self::$tempFiles[$tmpFile]=$path;
				return fopen('close://'.$tmpFile, $mode);
		}
	}

	public function writeBack($tmpFile) {
		if (isset(self::$tempFiles[$tmpFile])) {
			$this->uploadFile($tmpFile, self::$tempFiles[$tmpFile]);
			unlink($tmpFile);
		}
	}

	public function free_space($path) {
		$this->init();
		$path=$this->cleanPath($path);
		try {
			$response=$this->client->propfind($this->encodePath($path), array('{DAV:}quota-available-bytes'));
			if (isset($response['{DAV:}quota-available-bytes'])) {
				return (int)$response['{DAV:}quota-available-bytes'];
			} else {
				return \OC\Files\SPACE_UNKNOWN;
			}
		} catch(\Exception $e) {
			return \OC\Files\SPACE_UNKNOWN;
		}
	}

	public function touch($path, $mtime=null) {
		$this->init();
		if (is_null($mtime)) {
			$mtime=time();
		}
		$path=$this->cleanPath($path);

		// if file exists, update the mtime, else create a new empty file
		if ($this->file_exists($path)) {
			try {
				$this->client->proppatch($this->encodePath($path), array('{DAV:}lastmodified' => $mtime));
			}
			catch (\Sabre_DAV_Exception_NotImplemented $e) {
				return false;
			}
		} else {
			$this->file_put_contents($path, '');
		}
		return true;
	}

	public function getFile($path, $target) {
		$this->init();
		$source=$this->fopen($path, 'r');
		file_put_contents($target, $source);
	}

	public function uploadFile($path, $target) {
		$this->init();
		$source=fopen($path, 'r');

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERPWD, $this->user.':'.$this->password);
		curl_setopt($curl, CURLOPT_URL, $this->createBaseUri().str_replace(' ', '%20', $target));
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($curl, CURLOPT_INFILE, $source); // file pointer
		curl_setopt($curl, CURLOPT_INFILESIZE, filesize($path));
		curl_setopt($curl, CURLOPT_PUT, true);
		if ($this->secure === true) {
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
			if($this->certPath){
				curl_setopt($curl, CURLOPT_CAINFO, $this->certPath);
			}
		}
		curl_exec ($curl);
		$statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if ($statusCode !== 200) {
			\OCP\Util::writeLog("webdav client", 'curl GET ' . curl_getinfo($curl, CURLINFO_EFFECTIVE_URL) . ' returned status code ' . $statusCode, \OCP\Util::ERROR);
		}
		curl_close ($curl);
	}

	public function rename($path1, $path2) {
		$this->init();
		$path1 = $this->encodePath($this->cleanPath($path1));
		$path2 = $this->createBaseUri().$this->encodePath($this->cleanPath($path2));
		try {
			$this->client->request('MOVE', $path1, null, array('Destination'=>$path2));
			return true;
		} catch(\Exception $e) {
			return false;
		}
	}

	public function copy($path1, $path2) {
		$this->init();
		$path1 = $this->encodePath($this->cleanPath($path1));
		$path2 = $this->createBaseUri().$this->encodePath($this->cleanPath($path2));
		try {
			$this->client->request('COPY', $path1, null, array('Destination'=>$path2));
			return true;
		} catch(\Exception $e) {
			return false;
		}
	}

	public function stat($path) {
		$this->init();
		$path=$this->cleanPath($path);
		try {
			$response = $this->client->propfind($this->encodePath($path), array('{DAV:}getlastmodified', '{DAV:}getcontentlength'));
			return array(
				'mtime'=>strtotime($response['{DAV:}getlastmodified']),
				'size'=>(int)isset($response['{DAV:}getcontentlength']) ? $response['{DAV:}getcontentlength'] : 0,
			);
		} catch(\Exception $e) {
			return array();
		}
	}

	public function getMimeType($path) {
		$this->init();
		$path=$this->cleanPath($path);
		try {
			$response=$this->client->propfind($this->encodePath($path), array('{DAV:}getcontenttype', '{DAV:}resourcetype'));
			$responseType = array();
			if (isset($response["{DAV:}resourcetype"])) {
				$responseType=$response["{DAV:}resourcetype"]->resourceType;
			}
			$type=(count($responseType)>0 and $responseType[0]=="{DAV:}collection")?'dir':'file';
			if ($type=='dir') {
				return 'httpd/unix-directory';
			} elseif (isset($response['{DAV:}getcontenttype'])) {
				return $response['{DAV:}getcontenttype'];
			} else {
				return false;
			}
		} catch(\Exception $e) {
			return false;
		}
	}

	public function cleanPath($path) {
		$path = \OC\Files\Filesystem::normalizePath($path);
		// remove leading slash
		return substr($path, 1);
	}

	/**
	 * URL encodes the given path but keeps the slashes
	 * @param string $path to encode
	 * @return string encoded path
	 */
	private function encodePath($path) {
		// slashes need to stay
		return str_replace('%2F', '/', rawurlencode($path));
	}

	private function simpleResponse($method, $path, $body, $expected) {
		$path=$this->cleanPath($path);
		try {
			$response=$this->client->request($method, $this->encodePath($path), $body);
			return $response['statusCode']==$expected;
		} catch(\Exception $e) {
			return false;
		}
	}
}

