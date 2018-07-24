<?php

abstract class cJsonHelper {

	//string
	//path to the db file
	private $_path_db = NULL;

	//int
	//id of the object in db file
	private $_jsonId = NULL;

	//string
	//a buffer for fc (file content)
	private $_jsonFc = NULL;

	//boolean
	//if true, error/warnings log will be DISPLAYED
	static $verbose = false;

	/*
	GET/SET
	*/

	public function getJsonId() { return ($this->_jsonId); }

	private function log($str, $code = 0) {
		switch ($code) {
			case 0:
				$header = "error: ";
				break;
			case 1:
				$header = "warning: ";
				break;
			default:
				$header = "undefined: ";
		}
		if (Self::$verbose) {
			echo $header.$str."<br>";
		}
	}

	private function prepareDb() {
		$this->_path_db = get_class($this).".db.json";
		if (fopen($this->_path_db, 'c+'))
			return(true);
		return (false);
	}

	private function getDispId() {
		$id = 0;
		foreach ($this->_jsonFc as $filec ) {
			if ($filec["id"] > $id)
				$id = $filec["id"];
		}
		if ($id == NULL)
			return (1);
		return ($id + 1);
	}

	private function getFileContent() {
		$this->_jsonFc = file_get_contents($this->_path_db);
		if (!$this->_jsonFc)
			return (false);
		return (true);
	}

	static function isJson($string) {
		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}

	//convert and insert the data from object into db JSON file
	public function insertJson() {
		$this->prepareDb();
		$json = $this->expJson();
		if (!$this->getFileContent()) {
			file_put_contents($this->_path_db, json_encode([["id"=>0, "json"=>$json, "class"=>get_class($this)]]));
			$this->_jsonId = 0;
		} else {
			$this->_jsonFc = json_decode($this->_jsonFc, true);
			if (is_array($this->_jsonFc)) {
				$this->_jsonId = $this->getDispId();
				array_push($this->_jsonFc, ["id"=>$this->_jsonId, "json"=>$json, "class"=>get_class($this)]);
				$this->_jsonFc = json_encode($this->_jsonFc, true);
				file_put_contents($this->_path_db, $this->_jsonFc);
			}
		}
		$this->clearDump();
	}

	//convert the object's data to JSON format
	private function expJson() {
		$reflect = new ReflectionClass(get_class($this));
		$prop = $reflect->getProperties();
		if (is_array($prop)) {
			$arr = [];
			foreach ($prop as $key => $attr) {
				if ($attr->class == get_class($this) || $attr->name == "_jsonClass") {
					$attr_data = $attr->name;
					$val = $this->expJson_parse($this->$attr_data);
					$arr[$attr->name] = $val;
				}
			}
		}
		return (json_encode($arr));
	}

	private function expJson_parse($ad) {
		$val = NULL;
		switch(gettype($ad)) {
			default:
				$val = $ad;
				break;
			case "array":
				$val = [];
				foreach ($ad as $key => $data) {
					array_push($val, $this->expJson_parse($data));
				}
				break;
			case "object":
				$val = [];
				if (is_subclass_of($ad, "cJsonHelper")) {
					$ad->insertJson();
					$val["id"] = $ad->getJsonId();
					$val["class"] = get_class($ad);
				}
		}
		return ($val);
	}

	//import object's data from JSON file database
	public function importJson($id) {
		$this->prepareDb();
		if (!$this->getFileContent()) {
			$this->log("empty db", 0);
			return (false);
		}
		$this->_jsonFc = json_decode($this->_jsonFc, true);
		if (is_array($this->_jsonFc)) {
			$this->_jsonId = -1;
			foreach($this->_jsonFc as $filec) {
				if ($filec["id"] == $id) {
					$this->_jsonId = $id;
					$this->impJson($filec["json"], $filec["class"]);
				}
			}
			if ($this->_jsonId == -1 && Self::$verbose) {
				$this->log("can't find id", 0);
				return (false);
			}
		}
		$this->clearDump();
	}

	//import the object's data from a JSON file
	private function impJson($json, $class) {
		$arr = json_decode($json, true);
		if (is_array($arr)) {
			foreach ($arr as $key => $attr) {
				$this->$key = $this->impJson_parse($attr, $class);
			}
		}
	}

	private function impJson_parse($ad, $class) {
		$val = NULL;
		switch (gettype($ad)) {
			default:
				$val = $ad;
				break;
			case "string":
				if (Self::isJson($ad)) {
					$val = new $class();
					$val->impJson($ad, $class);
				}
				else
					$val = $ad;
				break;
			case "array":
				if (isset($ad["class"])) {
					$val = new $ad["class"]();
					$val->importJson($ad["id"]);
				} else {
					$val = [];
					foreach ($ad as $key => $data) {
						$val[] = $this->impJson_parse($data, $class);
					}
				}
				break;
		}
		return ($val);
	}

	//TODO
	//update the database with new data from an object
	public function updateJson() {
		$this->prepareDb();
		if (!$this->getFileContent()) {
			$this->log("empty db", 0);
			return (false);
		}
		$this->_jsonFc = json_decode($this->_jsonFc, true);
		if (is_array($this->_jsonFc)) {
			$found = false;
			foreach ($this->_jsonFc as $key => $fc) {
				if (isset($fc["id"]) && $fc["id"] == $this->_jsonId) {
					$this->_jsonFc[$key]["json"] = $this->expJson();
					$found = true;
				}
			}
			if ($found) {
				file_put_contents($this->_path_db, json_encode($this->_jsonFc));
			} else {
				$this->log("id not found in db", 0);
			}
		}
		$this->clearDump();
	}

	public function deleteJson() {
		$this->prepareDb();
		if (!$this->getFileContent()) {
			$this->log("empty db", 0);
			return (false);
		}
		$this->_jsonFc = json_decode($this->_jsonFc, true);
		if (is_array($this->_jsonFc) && isset($this->_jsonFc[$this->_jsonId])) {
			unset($this->_jsonFc[$this->_jsonId]);
			file_put_contents($this->_path_db, json_encode($this->_jsonFc));
		}
		$this->clearDump();
	}

	private function clearDump() {
		unset($this->_jsonFc);
		$this->_jsonFc = NULL;
	}
}

?>